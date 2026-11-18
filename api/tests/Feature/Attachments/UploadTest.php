<?php

namespace Tests\Feature\Attachments;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Transaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** AG-062 upload and validation: attachments spec AC 1-9 (+ R7, R9). */
class UploadTest extends AttachmentsTestCase
{
    public function test_ac1_cashier_uploads_a_receipt(): void
    {
        $res = $this->upload($this->csh, $this->kdy, $this->e['id'], $this->file('r1.jpg', self::jpeg(204_800), 'image/jpeg'))->assertCreated();
        $a = $res->json();
        $this->assertSame('image/jpeg', $a['mime']);
        $this->assertSame('jpg', $a['extension']);
        $this->assertSame(204_800, $a['size_bytes']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a['sha256']);
        $this->assertSame('r1.jpg', $a['original_name']);
        $this->assertSame(['id' => $this->csh->id, 'name' => $this->csh->name, 'tag' => null], $a['uploaded_by']);
        $this->assertNull($a['added_after']);
        $this->assertTrue($a['can_remove']);
        foreach (['path', 'url', 'disk'] as $k) {
            $this->assertArrayNotHasKey($k, $a);
        }
        $this->assertStringNotContainsString('attachments/', $res->getContent());

        $files = $this->disk()->allFiles();
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression("#^{$this->kdy->id}/{$this->e['id']}/[0-9a-f-]{36}\\.jpg$#", $files[0]);
        $this->assertSame($a['sha256'], hash('sha256', $this->disk()->get($files[0])));
        $this->assertSame(self::jpeg(204_800), $this->disk()->get($files[0]), 'bytes stored exactly as received');
        $this->assertSame($this->kdy->id, Attachment::withoutGlobalScopes()->sole()->branch_id);

        $audit = AuditLog::where('event', 'attachment.added')->sole();
        $this->assertSame($this->kdy->id, $audit->branch_id);
        $this->assertSame([Transaction::class, $this->e['id'], $this->csh->id], [$audit->auditable_type, $audit->auditable_id, $audit->user_id]);
        $this->assertNull($audit->old_values);
        $this->assertEquals([
            'attachment_id' => $a['id'], 'ref_no' => $this->e['ref_no'], 'original_name' => 'r1.jpg', 'mime' => 'image/jpeg',
            'size_bytes' => 204_800, 'sha256' => $a['sha256'], 'txn_status' => 'posted', 'period_locked' => false,
            'own_entry' => true, 'added_after' => null, 'also_on' => [],
        ], $audit->new_values);
    }

    public function test_ac2_size_limits(): void
    {
        $this->upload($this->csh, $this->kdy, $this->e['id'], $this->file('max.pdf', self::pdf(5_242_880)))->assertCreated()
            ->assertJsonPath('size_bytes', 5_242_880);
        $this->upload($this->csh, $this->kdy, $this->e['id'], $this->file('big.pdf', self::pdf(5_242_881, 'b')))
            ->assertStatus(422)->assertJsonPath('message', 'Each file may be at most 5 MB.');
        $this->upload($this->csh, $this->kdy, $this->e['id'], $this->file('empty.pdf', ''))
            ->assertStatus(422)->assertJsonPath('message', 'The file is empty.');
        // Refused by PHP's upload_max_filesize: same message.
        $tmp = $this->file('huge.pdf', self::pdf(1024, 'h'));
        $this->upload($this->csh, $this->kdy, $this->e['id'], new UploadedFile($tmp->getPathname(), 'huge.pdf', null, UPLOAD_ERR_INI_SIZE, true))
            ->assertStatus(422)->assertJsonPath('message', 'Each file may be at most 5 MB.');
        $this->as($this->csh, $this->kdy)->postJson("/api/v1/transactions/{$this->e['id']}/attachments", [])
            ->assertStatus(422)->assertJsonPath('message', 'Choose a file to attach.');
        $this->assertCount(1, $this->disk()->allFiles());
        $this->assertSame(1, Attachment::count());
        $this->assertSame(1, AuditLog::where('event', 'like', 'attachment.%')->count(), 'no audit row for failed validation');
    }

    public function test_ac3_only_jpg_png_pdf_by_content(): void
    {
        $bad = [
            'evil.jpg' => "<?php system(\$_GET['c']); ?>",
            'x.gif' => "GIF89a\x01\x00\x01\x00\x80\x00\x00\xFF\xFF\xFF\x00\x00\x00!\xF9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;",
            'x.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            'x.heic' => "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic".str_repeat("\x00", 64),
            'page.pdf' => '<html><body>%PDF-1.4</body></html>',
            'half.png' => "\x89PNG".str_repeat('A', 64), // a partial signature is not enough
        ];
        foreach ($bad as $name => $bytes) {
            foreach (['image/jpeg', 'application/pdf', 'image/png'] as $clientMime) {
                $this->upload($this->csh, $this->kdy, $this->e['id'], $this->file($name, $bytes, $clientMime))
                    ->assertStatus(422)->assertJsonPath('message', 'Only JPG, PNG or PDF files can be attached.');
            }
        }
        $this->assertSame([], $this->disk()->allFiles());
        $this->assertSame(0, Attachment::count());
    }

    public function test_ac4_extension_must_match_the_content(): void
    {
        $this->upload($this->csh, $this->kdy, $this->e['id'], $this->file('fake.pdf', self::png(), 'application/pdf'))
            ->assertStatus(422)->assertJsonPath('message', 'fake.pdf is a PNG image, not a PDF. Rename it or choose the right file.');
        $this->upload($this->csh, $this->kdy, $this->e['id'], $this->file('bill.png', self::pdf(2048)))
            ->assertStatus(422)->assertJsonPath('message', 'bill.png is a PDF, not a PNG. Rename it or choose the right file.');
        $this->upload($this->csh, $this->kdy, $this->e['id'], $this->file('scan.txt', self::pdf(2048)))
            ->assertStatus(422)->assertJsonPath('message', 'scan.txt is a PDF, but its name does not end in .pdf. Rename it or choose the right file.');
        $this->assertSame(0, Attachment::count());

        // jpg and jpeg are both JPEG; upper case is fine; the stored name gets the canonical extension.
        $this->assertSame('photo.jpg', $this->attach('photo.jpeg', self::jpeg(4096, 'p'))['original_name']);
        $this->assertSame('Invoice.pdf', $this->attach('Invoice.PDF', self::pdf(4096, 'i'))['original_name']);
        $this->assertSame('image/png', $this->attach('Scan.PNG', self::png(4096, 's'))['mime']);
    }

    public function test_ac5_at_most_five_active_files(): void
    {
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[] = $this->attach("r{$i}.jpg")['id'];
        }
        $this->upload($this->csh, $this->kdy, $this->e['id'], $this->file('r6.jpg', self::jpeg(seed: 'r6')))
            ->assertStatus(422)->assertJsonPath('message', 'An entry can have at most 5 files. Remove one first.');
        $this->assertCount(5, $this->disk()->allFiles());
        $this->listing($this->csh, $this->kdy, $this->e['id'])->assertOk()->assertJsonPath('max', 5)->assertJsonCount(5, 'data')
            ->assertJsonPath('can_add', true);

        $this->remove($this->csh, $this->kdy, $this->e['id'], $ids[0])->assertOk();
        $this->attach('r6.jpg');
        $this->assertSame(5, Attachment::whereNull('removed_at')->count());
    }

    public function test_ac6_at_most_ten_uploads_in_total(): void
    {
        foreach (range(1, 10) as $i) {
            $id = $this->attach("r{$i}.jpg")['id'];
            if ($i <= 7) {
                $this->remove($this->csh, $this->kdy, $this->e['id'], $id)->assertOk();
            }
        }
        $this->assertSame(3, Attachment::whereNull('removed_at')->count());
        $this->upload($this->csh, $this->kdy, $this->e['id'], $this->file('r11.jpg', self::jpeg(seed: 'r11')))
            ->assertStatus(422)->assertJsonPath('message', 'This entry already had 10 files uploaded (including removed ones). No more can be added.');
        $this->assertCount(10, $this->disk()->allFiles());
        $this->listing($this->csh, $this->kdy, $this->e['id'])->assertJsonPath('total_uploaded', 10)->assertJsonPath('max_total', 10)
            ->assertJsonPath('removed_count', 7)->assertJsonPath('active_count', 3);
    }

    public function test_ac7_same_bytes_twice_on_one_entry(): void
    {
        $inv = self::pdf();
        $this->attach('inv.pdf', $inv);
        $this->upload($this->csh, $this->kdy, $this->e['id'], $this->file('copy of invoice.pdf', $inv))
            ->assertStatus(422)->assertJsonPath('message', "This file is already attached to {$this->e['ref_no']}.");
        $f = $this->expense('2026-11-11', '50.00', $this->csh);
        $this->attach('inv.pdf', $inv, txnId: $f['id']);
        $this->assertSame(2, Attachment::count());
    }

    public function test_ac8_filenames_are_cleaned_and_never_reach_the_path(): void
    {
        $names = [
            '../../etc/passwd.PDF' => 'passwd.pdf',
            "a\u{202E}gnp.pdf" => 'agnp.pdf',
            '   .pdf' => 'attachment.pdf',
            str_repeat('n', 300).'.pdf' => str_repeat('n', 120).'.pdf',
            'C:\\Users\\me\\bill: "May" <1>.pdf' => 'bill_ _May_ _1_.pdf',
            "tab\tand   spaces .. .pdf" => 'taband spaces.pdf', // control characters go first (R7 step 3)
        ];
        $i = 0;
        foreach ($names as $original => $expected) {
            $i++;
            if ($i === 6) { // a fresh entry: at most 5 active files per entry
                $this->e = $this->expense('2026-11-12', '10.00', $this->csh);
            }
            $this->assertSame($expected, $this->attach($original, self::pdf(4096, "n{$i}"))['original_name'], $original);
        }
        foreach (Attachment::all() as $a) {
            $this->assertMatchesRegularExpression('#^\d+/\d+/[0-9a-f-]{36}\.pdf$#', $a->path);
        }
        foreach ($this->disk()->allFiles() as $p) {
            $this->assertMatchesRegularExpression('#^\d+/\d+/[0-9a-f-]{36}\.pdf$#', $p);
        }
    }

    public function test_ac9_parallel_uploads_cannot_make_six(): void
    {
        foreach (range(1, 4) as $i) {
            $this->attach("r{$i}.jpg");
        }
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = strtolower($q->sql);
        });
        // Two uploads for E: the count check runs inside a DB transaction holding the entry row lock, so the second
        // one sees the first one's row and is refused.
        // Reviewer N5: this is NOT a truly parallel test. Feature tests run requests one after another in one process
        // (and SQLite ignores FOR UPDATE), so it proves the count rule plus, on MySQL, that the entry row is read with
        // FOR UPDATE inside the upload transaction - which is what serialises real concurrent uploads.
        $this->upload($this->csh, $this->kdy, $this->e['id'], $this->file('a.jpg', self::jpeg(seed: 'a5')))->assertCreated();
        $this->upload($this->csh, $this->kdy, $this->e['id'], $this->file('b.jpg', self::jpeg(seed: 'b5')))->assertStatus(422);
        $this->assertSame(5, Attachment::whereNull('removed_at')->count());
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->assertTrue(collect($sql)->contains(fn ($s) => str_contains($s, 'from `transactions`') && str_contains($s, 'for update')));
        }
    }

    public function test_r9_entry_flows_ignore_attachments_and_count_is_active_only(): void
    {
        $a = $this->attach('r1.jpg');
        $this->attach('r2.jpg');
        $this->remove($this->csh, $this->kdy, $this->e['id'], $a['id'])->assertOk();
        $this->accKdy()->getJson("/api/v1/transactions/{$this->e['id']}")->assertOk()
            ->assertJsonPath('attachments_count', 1)->assertJsonPath('attachments_removed_count', 1);
        $row = collect($this->accKdy()->getJson('/api/v1/transactions')->json('data'))->firstWhere('id', $this->e['id']);
        $this->assertSame(1, $row['attachments_count']);

        $this->accKdy()->postJson("/api/v1/transactions/{$this->e['id']}/void", ['reason' => 'Duplicate'])->assertOk();
        $this->assertSame(2, Attachment::where('transaction_id', $this->e['id'])->count(), 'void keeps every attachment');
        $this->assertCount(2, $this->disk()->allFiles());
    }
}
