<?php

namespace Tests\Feature\Attachments;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Controls\ControlsTestCase;

/**
 * Setup of docs/specs/attachments.md section 7 on top of the controls setup (MAIN, KDY, GAL; SA, ACC, VW, CSH, BA;
 * today 2026-11-18): CSH2 (second KDY Cashier), entry E = KDY expense recorded by CSH on 2026-11-10,
 * Storage::fake('attachments'). Test files carry real JPEG / PNG / PDF magic bytes.
 */
abstract class AttachmentsTestCase extends ControlsTestCase
{
    protected User $csh2;

    /** Entry E (array from the API). */
    protected array $e;

    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Attachment::DISK);
        $this->csh2 = $this->userIn($this->kdy, 'Cashier');
        $this->e = $this->expense('2026-11-10', '100.00', $this->csh);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------ files with real magic bytes

    /** A JPEG of exactly $size bytes (JFIF header, unique filler, EOI marker). */
    protected static function jpeg(int $size = 204_800, string $seed = 'a'): string
    {
        $head = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xFE\x00\x10".str_pad(substr($seed, 0, 14), 14, '-');

        return self::fill($head, "\xFF\xD9", $size, $seed);
    }

    /** A PNG of exactly $size bytes (signature + IHDR, unique filler). */
    protected static function png(int $size = 2_048, string $seed = 'a'): string
    {
        $head = "\x89PNG\r\n\x1A\n\x00\x00\x00\x0DIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90\x77\x53\xDE";

        return self::fill($head, "\x00\x00\x00\x00IEND\xAE\x42\x60\x82", $size, $seed);
    }

    /** A PDF of exactly $size bytes. */
    protected static function pdf(int $size = 1_048_576, string $seed = 'a'): string
    {
        return self::fill("%PDF-1.4\n%{$seed}\n1 0 obj\n<< /Type /Catalog >>\nendobj\n", "\n%%EOF\n", $size, $seed);
    }

    private static function fill(string $head, string $tail, int $size, string $seed): string
    {
        $room = $size - strlen($head) - strlen($tail);
        $chunk = hash('sha256', $seed, true);

        return $head.substr(str_repeat($chunk, intdiv(max($room, 0), 32) + 1), 0, max($room, 0)).$tail;
    }

    /** An uploaded file with the given name and bytes; the client Content-Type is whatever we say (never trusted). */
    protected function file(string $name, string $bytes, string $clientMime = 'application/octet-stream'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, $bytes);
        $this->tmp[] = $path;

        return new UploadedFile($path, $name, $clientMime, null, true);
    }

    // ------------------------------------------------------------------ API helpers

    protected function upload(User $u, $branch, int $txnId, UploadedFile $file): TestResponse
    {
        return $this->as($u, $branch)->withHeader('Accept', 'application/json')
            ->post("/api/v1/transactions/{$txnId}/attachments", ['file' => $file]);
    }

    /** Upload as CSH to E (or $txnId) and return the Attachment array. */
    protected function attach(string $name = 'r1.jpg', ?string $bytes = null, ?User $u = null, ?int $txnId = null, $branch = null): array
    {
        return $this->upload($u ?? $this->csh, $branch ?? $this->kdy, $txnId ?? $this->e['id'], $this->file($name, $bytes ?? self::jpeg(seed: $name)))
            ->assertCreated()->json();
    }

    protected function remove(User $u, $branch, int $txnId, int $attId, string $reason = 'Wrong bill'): TestResponse
    {
        return $this->as($u, $branch)->postJson("/api/v1/transactions/{$txnId}/attachments/{$attId}/remove", ['reason' => $reason]);
    }

    protected function download(User $u, $branch, int $txnId, int $attId): TestResponse
    {
        return $this->as($u, $branch)->getJson("/api/v1/transactions/{$txnId}/attachments/{$attId}/download");
    }

    protected function listing(User $u, $branch, int $txnId, bool $removed = false): TestResponse
    {
        return $this->as($u, $branch)->getJson("/api/v1/transactions/{$txnId}/attachments".($removed ? '?include_removed=1' : ''));
    }

    protected function disk()
    {
        return Storage::disk(Attachment::DISK);
    }

    /** Overwrite a stored file outside the app (read-only on disk, so make it writable first). */
    protected function tamper(int $attId, ?string $bytes): void
    {
        $path = Attachment::withoutGlobalScopes()->findOrFail($attId)->path;
        @chmod($this->disk()->path($path), 0640);
        $bytes === null ? $this->disk()->delete($path) : $this->disk()->put($path, $bytes);
    }
}
