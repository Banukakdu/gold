<?php

namespace Tests\Feature\BankStatementAttachments;

use App\Exceptions\ImmutableRecordException;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\BankStatement;
use App\Services\StatementAttachments;
use Illuminate\Support\Facades\DB;

/** AG-033b upload, limits, duplicates and replace: bank-statement-attachment.md AC 1-6, 23, 25. */
class UploadTest extends StatementFilesTestCase
{
    public function test_ac1_accountant_uploads_the_statement_copy(): void
    {
        $res = $this->upload($this->acc, file: $this->file('oct.pdf', self::bytes('oct.pdf'), 'application/pdf'))->assertCreated();
        $a = $res->json();
        $this->assertSame($this->s1->id, $a['bank_statement_id']);
        $this->assertSame('application/pdf', $a['mime']);
        $this->assertSame('pdf', $a['extension']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a['sha256']);
        $this->assertNull($a['added_after']);
        $this->assertSame([], $a['also_on']);
        $this->assertArrayNotHasKey('transaction_id', $a);
        foreach (['path', 'url', 'disk'] as $k) {
            $this->assertArrayNotHasKey($k, $a);
        }
        $this->assertStringNotContainsString('bank-statements/', $res->getContent());

        $files = $this->disk()->allFiles();
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression("#^{$this->kdy->id}/bank-statements/{$this->s1->id}/[0-9a-f-]{36}\\.pdf$#", $files[0]);
        $this->assertSame('0440', substr(sprintf('%o', fileperms($this->disk()->path($files[0]))), -4));
        $this->assertSame(self::bytes('oct.pdf'), $this->disk()->get($files[0]));
        $row = Attachment::withoutGlobalScopes()->sole();
        $this->assertSame([$this->kdy->id, null, $this->s1->id], [$row->branch_id, $row->transaction_id, (int) $row->bank_statement_id]);

        $audit = AuditLog::where('event', 'attachment.added')->sole();
        $this->assertSame([$this->kdy->id, BankStatement::class, $this->s1->id, $this->acc->id], [$audit->branch_id, $audit->auditable_type, $audit->auditable_id, $audit->user_id]);
        $this->assertNull($audit->old_values);
        $this->assertEquals([
            'parent' => 'bank_statement', 'account_id' => $this->kdyBank->id, 'statement_date' => '2026-10-31', 'statement_status' => 'completed',
            'attachment_id' => $a['id'], 'original_name' => 'oct.pdf', 'mime' => 'application/pdf', 'size_bytes' => $a['size_bytes'],
            'sha256' => $a['sha256'], 'added_after' => null, 'by_preparer' => true, 'also_on' => [], 'replaces_id' => null,
        ], $audit->new_values);
    }

    public function test_ac2_ag062_validation_messages(): void
    {
        $this->upload(file: $this->file('max.pdf', self::pdf(5_242_880, 'max')))->assertCreated()->assertJsonPath('size_bytes', 5_242_880);
        $cases = [
            ['big.pdf', self::pdf(5_242_881, 'big'), 'Each file may be at most 5 MB.'],
            ['empty.pdf', '', 'The file is empty.'],
            ['evil.jpg', "<?php system(\$_GET['c']); ?>", 'Only JPG, PNG or PDF files can be attached.'],
            ['fake.pdf', self::png(), 'fake.pdf is a PNG image, not a PDF. Rename it or choose the right file.'],
        ];
        foreach ($cases as [$name, $bytes, $message]) {
            $this->upload(file: $this->file($name, $bytes, 'application/pdf'))->assertStatus(422)->assertJsonPath('message', $message);
        }
        $this->as($this->acc, $this->kdy)->postJson($this->base(), [])->assertStatus(422)->assertJsonPath('message', 'Choose a file to attach.');
        $this->assertCount(1, $this->disk()->allFiles());
        $this->assertSame(1, Attachment::withoutGlobalScopes()->count());
        $this->assertSame(1, AuditLog::where('event', 'like', 'attachment.%')->count(), 'no audit row for failed validation');
    }

    public function test_ac2_body_over_post_max_size_is_422_not_413(): void
    {
        $tooBig = ['CONTENT_LENGTH' => (string) (1024 ** 3)];
        $this->as($this->acc, $this->kdy)->withServerVariables($tooBig)->withHeader('Accept', 'application/json')
            ->post($this->base())->assertStatus(422)->assertJsonPath('message', 'Each file may be at most 5 MB.')->assertJsonValidationErrors('file');
        $this->assertSame([], $this->disk()->allFiles());
    }

    public function test_ac3_five_active_and_ten_total(): void
    {
        $ids = [];
        foreach (range(1, 5) as $i) {
            $ids[] = $this->attach("p{$i}.jpg")['id'];
        }
        $this->upload(file: $this->file('p6.jpg', self::bytes('p6.jpg')))->assertStatus(422)
            ->assertJsonPath('message', 'A statement can have at most 5 files. Remove one first.');
        // A replace at 5 active files does not grow the count.
        $this->replace($ids[0], 'r1.jpg', 'Blurred scan')->assertCreated();
        $this->assertSame(5, Attachment::whereNull('removed_at')->count());
        foreach (range(2, 5) as $i) {
            $this->replace($ids[$i - 1], "r{$i}.jpg", 'Blurred scan')->assertCreated();
        }
        $this->assertSame(10, Attachment::count());
        $active = Attachment::whereNull('removed_at')->first();
        $this->replace($active->id, 'r11.jpg', 'Blurred scan')->assertStatus(422)
            ->assertJsonPath('message', 'This statement already had 10 files uploaded (including removed ones). No more can be added.');
        $this->remove($active->id)->assertOk();
        $this->upload(file: $this->file('p11.jpg', self::bytes('p11.jpg')))->assertStatus(422)
            ->assertJsonPath('message', 'This statement already had 10 files uploaded (including removed ones). No more can be added.');
        $this->assertSame(10, Attachment::count());
    }

    public function test_ac4_same_bytes_on_one_statement_and_on_the_replacing_statement(): void
    {
        $this->attach('oct.pdf');
        $this->upload(file: $this->file('copy.pdf', self::bytes('oct.pdf')))->assertStatus(422)
            ->assertJsonPath('message', 'This file is already attached to this statement.');

        $this->undo($this->s1->id)->assertOk();
        $s1b = BankStatement::withoutGlobalScopes()->findOrFail($this->complete('2026-10-31', '479500.00')->assertCreated()->json('id'));
        $a = $this->upload(s: $s1b, file: $this->file('oct.pdf', self::bytes('oct.pdf')))->assertCreated()->json();
        $this->assertSame([['statement_id' => $this->s1->id, 'statement_date' => '2026-10-31', 'status' => 'undone']], $a['also_on']);
        $this->assertSame($a['also_on'], $this->listing(s: $s1b)->json('data.0.also_on'));
        // MySQL JSON reorders keys.
        $this->assertEquals($a['also_on'], AuditLog::where('event', 'attachment.added')->latest('id')->first()->new_values['also_on']);
    }

    public function test_ac5_parallel_uploads_cannot_make_six(): void
    {
        foreach (range(1, 4) as $i) {
            $this->attach("p{$i}.jpg");
        }
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = strtolower($q->sql);
        });
        // Not truly parallel (feature tests run one request after another, and SQLite ignores FOR UPDATE): it proves the
        // count rule and, on MySQL, that the statement row is read FOR UPDATE inside the upload transaction.
        $this->upload(file: $this->file('a.jpg', self::bytes('a.jpg')))->assertCreated();
        $this->upload(file: $this->file('b.jpg', self::bytes('b.jpg')))->assertStatus(422);
        $this->assertSame(5, Attachment::whereNull('removed_at')->count());
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->assertTrue(collect($sql)->contains(fn ($s) => str_contains($s, 'from `bank_statements`') && str_contains($s, 'for update')));
        }
    }

    public function test_ac6_replace_wrong_month(): void
    {
        $sep = $this->attach('sep.pdf');
        $before = AuditLog::count();
        $res = $this->replace($sep['id'], 'oct.pdf', 'Wrong month')->assertCreated();
        $new = $res->json();
        $this->assertSame('oct.pdf', $new['original_name']);
        $this->assertNull($new['removed_at']);
        $this->assertSame($sep['id'], $res->json('replaced.id'));
        $this->assertSame($new['id'], $res->json('replaced.replaced_by_id'));
        $this->assertSame('oct.pdf', $res->json('replaced.replaced_by_name'));

        $old = Attachment::findOrFail($sep['id']);
        $this->assertNotNull($old->removed_at);
        $this->assertSame([$this->acc->id, 'Wrong month', $new['id']], [(int) $old->removed_by, $old->removal_reason, (int) $old->replaced_by_id]);
        $this->assertCount(2, $this->disk()->allFiles(), 'both files stay on disk');

        $this->assertSame($before + 2, AuditLog::count());
        $added = AuditLog::where('event', 'attachment.added')->latest('id')->first();
        $this->assertSame($sep['id'], $added->new_values['replaces_id']);
        $removed = AuditLog::where('event', 'attachment.removed')->sole();
        $this->assertSame([BankStatement::class, $this->s1->id, $this->kdy->id], [$removed->auditable_type, $removed->auditable_id, $removed->branch_id]);
        $this->assertSame($new['id'], $removed->new_values['replaced_by_id']);
        $this->assertSame('Wrong month', $removed->new_values['reason']);
        $this->assertTrue($removed->new_values['self']);
        $this->assertSame('bank_statement', $removed->new_values['parent']);
        $this->assertSame([$sep['id'], 'sep.pdf', $sep['sha256'], $this->acc->id, null, true],
            [$removed->old_values['attachment_id'], $removed->old_values['original_name'], $removed->old_values['sha256'],
                $removed->old_values['uploaded_by'], $removed->old_values['added_after'], $removed->old_values['by_preparer']]);
        $this->assertSame('bank_statement', $removed->old_values['parent']);
    }

    public function test_ac6_bad_replace_changes_nothing(): void
    {
        $sep = $this->attach('sep.pdf');
        $gone = $this->attach('aug.pdf');
        $this->remove($gone['id'])->assertOk();
        // Another statement of the same account (2026-11-30).
        $s2 = $this->statementTwo();
        $other = $this->attach('nov.pdf', s: $s2);
        $before = [Attachment::count(), AuditLog::count(), $this->disk()->allFiles()];

        $this->replace($sep['id'], 'oct.pdf', 'no')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->replace($sep['id'], 'oct.pdf', '')->assertStatus(422)->assertJsonValidationErrors('reason');
        foreach ([$gone['id'], $other['id'], 999999] as $id) {
            $this->replace($id, 'oct.pdf', 'Wrong month')->assertStatus(422)->assertJsonPath('message', 'Choose an active file of this statement to replace.');
        }
        $this->assertSame($before, [Attachment::count(), AuditLog::count(), $this->disk()->allFiles()]);
        $this->assertNull(Attachment::findOrFail($sep['id'])->removed_at);
    }

    public function test_reason_is_ignored_on_a_plain_upload(): void
    {
        // Review N7: only a replace validates `reason`.
        $this->upload(file: $this->file('oct.pdf', self::bytes('oct.pdf')), extra: ['reason' => 'x'])->assertCreated();
        $this->assertNull(Attachment::sole()->removal_reason);
    }

    public function test_ac23_by_preparer(): void
    {
        $a = $this->attach('oct.pdf', u: $this->acc);
        $this->assertTrue($a['by_preparer']);
        $this->assertTrue(AuditLog::where('event', 'attachment.added')->sole()->new_values['by_preparer']);
        $b = $this->attach('page2.pdf', u: $this->ba);
        $this->assertFalse($b['by_preparer']);
        $this->assertFalse(AuditLog::where('event', 'attachment.added')->latest('id')->first()->new_values['by_preparer']);
        $list = collect($this->listing($this->vw)->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($list[$a['id']]['by_preparer']);
        $this->assertFalse($list[$b['id']]['by_preparer']);
        // Removal rows record it too.
        $this->remove($b['id'])->assertOk();
        $this->assertFalse(AuditLog::where('event', 'attachment.removed')->sole()->old_values['by_preparer']);
    }

    public function test_ac25_same_bytes_on_the_september_statement(): void
    {
        // A 2026-09-30 statement after S1 is not possible (dates must increase), so S1 is undone, September completed
        // (nothing cleared by then: balance 0.00), and October completed again; that one is the S1 of this AC.
        $this->undo($this->s1->id)->assertOk();
        $sep = BankStatement::withoutGlobalScopes()->findOrFail($this->complete('2026-09-30', '0.00')->assertCreated()->json('id'));
        $this->attach('sep.pdf', s: $sep);
        $oct = BankStatement::withoutGlobalScopes()->findOrFail($this->complete('2026-10-31', '479500.00')->assertCreated()->json('id'));
        $a = $this->upload(s: $oct, file: $this->file('sep.pdf', self::bytes('sep.pdf')))->assertCreated()->json();
        $this->assertSame([['statement_id' => $sep->id, 'statement_date' => '2026-09-30', 'status' => 'completed']], $a['also_on']);

        $st = $this->report(['statement_id' => $oct->id])->assertOk()->json('statement.attachments.0');
        $this->assertSame($a['also_on'], $st['also_on']);
        $line = StatementAttachments::reprintLine($st);
        $this->assertStringEndsWith(' - also on the 2026-09-30 statement (completed)', $line);
        $pdf = implode(' ', $this->pdfPages($this->as($this->acc, $this->kdy)->postJson('/api/v1/reports/bank-reconciliation/export',
            ['statement_id' => $oct->id, 'format' => 'pdf'])->assertOk()->getContent()));
        $this->assertStringContainsString('also on the 2026-09-30 statement (completed)', $pdf);
        $xlsx = $this->xlsx($this->as($this->acc, $this->kdy)->postJson('/api/v1/reports/bank-reconciliation/export',
            ['statement_id' => $oct->id, 'format' => 'xlsx'])->assertOk());
        $this->assertStringContainsString($line, $this->sheetText($xlsx));
    }

    private function sheetText($xlsx): string
    {
        $out = [];
        foreach ($xlsx->getAllSheets() as $ws) {
            foreach ($ws->toArray(null, false, false) as $row) {
                $out[] = implode(' ', array_map(fn ($v) => (string) $v, $row));
            }
        }

        return implode("\n", $out);
    }

    public function test_bad_parent_rows_are_refused_by_the_model(): void
    {
        $this->expectException(ImmutableRecordException::class);
        Attachment::create(['branch_id' => $this->kdy->id, 'disk' => 'attachments', 'path' => 'x/y.pdf', 'original_name' => 'y.pdf',
            'mime' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $this->acc->id]);
    }
}
