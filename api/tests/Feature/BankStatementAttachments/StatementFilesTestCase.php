<?php

namespace Tests\Feature\BankStatementAttachments;

use App\Models\Attachment;
use App\Models\BankStatement;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\BankReconciliation\BankReconciliationTestCase;

/**
 * docs/specs/bank-statement-attachment.md section 7 setup: the AG-033 setup (MAIN, KDY, GAL; KDY Bank 11002, GAL Bank,
 * KDY Cash; SA, ACC, BA, VW, CSH, GACC), S1 = completed KDY Bank statement 2026-10-31 (completed by ACC),
 * Storage::fake('attachments'). Test files carry real JPEG / PNG / PDF magic bytes (as the AG-062 tests).
 */
abstract class StatementFilesTestCase extends BankReconciliationTestCase
{
    protected BankStatement $s1;

    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Attachment::DISK);
        $this->s1 = $this->statementOne();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------ files with real magic bytes

    protected static function jpeg(int $size = 204_800, string $seed = 'a'): string
    {
        $head = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xFE\x00\x10".str_pad(substr($seed, 0, 14), 14, '-');

        return self::fill($head, "\xFF\xD9", $size, $seed);
    }

    protected static function png(int $size = 2_048, string $seed = 'a'): string
    {
        $head = "\x89PNG\r\n\x1A\n\x00\x00\x00\x0DIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x02\x00\x00\x00\x90\x77\x53\xDE";

        return self::fill($head, "\x00\x00\x00\x00IEND\xAE\x42\x60\x82", $size, $seed);
    }

    protected static function pdf(int $size = 65_536, string $seed = 'a'): string
    {
        return self::fill("%PDF-1.4\n%{$seed}\n1 0 obj\n<< /Type /Catalog >>\nendobj\n", "\n%%EOF\n", $size, $seed);
    }

    private static function fill(string $head, string $tail, int $size, string $seed): string
    {
        $room = $size - strlen($head) - strlen($tail);
        $chunk = hash('sha256', $seed, true);

        return $head.substr(str_repeat($chunk, intdiv(max($room, 0), 32) + 1), 0, max($room, 0)).$tail;
    }

    protected function file(string $name, string $bytes, string $clientMime = 'application/octet-stream'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'bsa');
        file_put_contents($path, $bytes);
        $this->tmp[] = $path;

        return new UploadedFile($path, $name, $clientMime, null, true);
    }

    /** The bytes of a named test file (oct.pdf, sep.pdf: real PDFs with their own content). */
    protected static function bytes(string $name): string
    {
        return str_ends_with($name, '.jpg') ? self::jpeg(seed: $name) : self::pdf(seed: $name);
    }

    // ------------------------------------------------------------------ API helpers

    protected function base(?BankStatement $s = null, ?int $accountId = null): string
    {
        $s ??= $this->s1;

        return '/api/v1/accounts/'.($accountId ?? $s->account_id)."/reconciliation/statements/{$s->id}/attachments";
    }

    protected function branchOf(BankStatement $s): Branch
    {
        return Branch::findOrFail($s->branch_id);
    }

    protected function upload(?User $u = null, ?BankStatement $s = null, ?UploadedFile $file = null, array $extra = [], Branch|string|null $branch = null): TestResponse
    {
        $s ??= $this->s1;

        return $this->as($u ?? $this->acc, $branch ?? $this->branchOf($s))->withHeader('Accept', 'application/json')
            ->post($this->base($s), ['file' => $file ?? $this->file('oct.pdf', self::bytes('oct.pdf'))] + $extra);
    }

    /** Upload as ACC (default) and return the Attachment array. */
    protected function attach(string $name = 'oct.pdf', ?string $bytes = null, ?User $u = null, ?BankStatement $s = null): array
    {
        return $this->upload($u, $s, $this->file($name, $bytes ?? self::bytes($name)))->assertCreated()->json();
    }

    protected function replace(int $oldId, string $name = 'oct.pdf', string $reason = 'Wrong month', ?User $u = null, ?BankStatement $s = null, Branch|string|null $branch = null, ?string $bytes = null): TestResponse
    {
        return $this->upload($u, $s, $this->file($name, $bytes ?? self::bytes($name)), ['replaces_id' => $oldId, 'reason' => $reason], $branch);
    }

    protected function remove(int $attId, string $reason = 'Wrong statement', ?User $u = null, ?BankStatement $s = null, Branch|string|null $branch = null): TestResponse
    {
        $s ??= $this->s1;

        return $this->as($u ?? $this->acc, $branch ?? $this->branchOf($s))->postJson($this->base($s)."/{$attId}/remove", ['reason' => $reason]);
    }

    protected function download(int $attId, ?User $u = null, ?BankStatement $s = null, Branch|string|null $branch = null): TestResponse
    {
        $s ??= $this->s1;

        return $this->as($u ?? $this->acc, $branch ?? $this->branchOf($s))->getJson($this->base($s)."/{$attId}/download");
    }

    protected function listing(?User $u = null, ?BankStatement $s = null, bool $removed = false, Branch|string|null $branch = null): TestResponse
    {
        $s ??= $this->s1;

        return $this->as($u ?? $this->acc, $branch ?? $this->branchOf($s))->getJson($this->base($s).($removed ? '?include_removed=1' : ''));
    }

    protected function disk()
    {
        return Storage::disk(Attachment::DISK);
    }

    protected function tamper(int $attId, ?string $bytes): void
    {
        $path = Attachment::withoutGlobalScopes()->findOrFail($attId)->path;
        @chmod($this->disk()->path($path), 0640);
        $bytes === null ? $this->disk()->delete($path) : $this->disk()->put($path, $bytes);
    }

    protected function lock(string $period, ?Branch $b = null): void
    {
        $this->sa()->postJson('/api/v1/period-locks', ['period' => $period, 'branch_id' => ($b ?? $this->kdy)->id])->assertCreated();
    }

    /** History item of a statement on S1 (ACC, KDY). */
    protected function historyItem(BankStatement $s): array
    {
        return collect($this->screen(['statement_date' => '2026-11-20'])->assertOk()->json('statements'))->firstWhere('id', $s->id);
    }

    /** Everything a file action must never change (R7, AC 13). */
    protected function frozen(): array
    {
        return $this->books() + [
            'marks' => DB::table('bank_reconciliation_marks')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            'statements' => DB::table('bank_statements')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }
}
