<?php

namespace Tests\Feature\Attachments;

use App\Models\AuditLog;

/**
 * AG-062 evidence review: attachments spec AC 33 + accounting review AC 38 (month-end review), 39 (missing evidence),
 * 40 (same receipt on two entries), 41 (attachments:verify).
 */
class EvidenceReviewTest extends AttachmentsTestCase
{
    private const NOV = ['from' => '2026-11-01', 'to' => '2026-11-30'];

    public function test_ac33_audit_log_group_and_ref_filter(): void
    {
        $a = $this->attach('r1.jpg');
        $this->download($this->vw, $this->kdy, $this->e['id'], $a['id'])->assertOk();
        $this->remove($this->acc, $this->kdy, $this->e['id'], $a['id'])->assertOk();
        $this->accKdy()->postJson("/api/v1/transactions/{$this->e['id']}/void", ['reason' => 'Duplicate'])->assertOk();
        $this->tamper($a['id'], null);
        $this->download($this->vw, $this->kdy, $this->e['id'], $a['id'])->assertStatus(410);

        $rows = $this->accKdy()->getJson('/api/v1/audit-logs?'.http_build_query(self::NOV + ['event_group' => 'attachments']))->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['attachment.added', 'attachment.downloaded', 'attachment.removed', 'attachment.missing'], array_column($rows, 'event'));
        foreach ($rows as $r) {
            $this->assertSame($this->e['ref_no'], $r['subject']);
        }
        $labels = array_column($rows, 'event_label', 'event');
        $this->assertSame(['Attachment added', 'Attachment downloaded', 'Attachment removed', 'Attachment file missing'],
            [$labels['attachment.added'], $labels['attachment.downloaded'], $labels['attachment.removed'], $labels['attachment.missing']]);

        $byRef = $this->accKdy()->getJson('/api/v1/audit-logs?'.http_build_query(self::NOV + ['ref_no' => $this->e['ref_no']]))->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['transaction.expense', 'transaction.voided', 'attachment.added', 'attachment.downloaded', 'attachment.removed', 'attachment.missing'],
            array_column($byRef, 'event'));

        // The "transactions" group is unchanged: no attachment rows in it.
        $tx = $this->accKdy()->getJson('/api/v1/audit-logs?'.http_build_query(self::NOV + ['event_group' => 'transactions']))->json('data');
        $this->assertEmpty(array_filter(array_column($tx, 'event'), fn ($e) => str_starts_with($e, 'attachment.')));
        // "Self only" finds own removals (finding 8).
        $b = $this->attach('r2.jpg');
        $this->accKdy()->getJson("/api/v1/transactions/{$this->e['id']}")->assertOk();
        $f = $this->expense('2026-11-12', '10.00', $this->csh);
        $c = $this->attach('r3.jpg', txnId: $f['id']);
        $this->remove($this->csh, $this->kdy, $f['id'], $c['id'], 'Blurred')->assertOk();
        $self = $this->accKdy()->getJson('/api/v1/audit-logs?'.http_build_query(self::NOV + ['event_group' => 'attachments', 'self' => 1]))->json('data');
        $this->assertSame(['attachment.removed'], array_column($self, 'event'));
        $this->assertSame($f['ref_no'], $self[0]['subject']);
        $this->assertNotNull($b);
    }

    public function test_ac38_month_end_review_counts_file_removals(): void
    {
        $o1 = $this->expense('2026-10-05', '100.00', $this->csh);
        $o2 = $this->expense('2026-10-06', '100.00', $this->csh);
        $a = $this->attach('a.jpg', txnId: $o1['id']);
        $b = $this->attach('b.jpg', txnId: $o1['id']);
        $c = $this->attach('c.jpg', txnId: $o2['id']);
        $this->remove($this->csh, $this->kdy, $o1['id'], $a['id'])->assertOk(); // by the uploader
        $this->remove($this->csh, $this->kdy, $o1['id'], $b['id'])->assertOk(); // by the uploader
        $this->remove($this->acc, $this->kdy, $o2['id'], $c['id'])->assertOk(); // by ACC
        $nov = $this->attach('n.jpg');
        $this->remove($this->csh, $this->kdy, $this->e['id'], $nov['id'])->assertOk(); // another month
        $selfVoid = $this->expense('2026-10-07', '10.00');
        $this->accKdy()->postJson("/api/v1/transactions/{$selfVoid['id']}/void", ['reason' => 'Duplicate'])->assertOk();

        $this->sa()->getJson("/api/v1/period-locks/review?period=2026-10&branch_id={$this->kdy->id}")->assertOk()
            ->assertJsonPath('self_voids', 1)->assertJsonPath('files_removed', 3)->assertJsonPath('files_removed_self', 2);
        $this->sa()->getJson("/api/v1/period-locks/review?period=2026-10&branch_id={$this->gal->id}")->assertOk()
            ->assertJsonPath('files_removed', 0);
        // Locking November too (cumulative) covers October and November.
        $this->sa()->getJson("/api/v1/period-locks/review?period=2026-11&branch_id={$this->kdy->id}")->assertOk()
            ->assertJsonPath('files_removed', 4)->assertJsonPath('files_removed_self', 3);
        // Information only: the lock still works, and afterwards October is no longer "newly closed".
        $this->lock('2026-10', $this->kdy)->assertCreated();
        $this->sa()->getJson("/api/v1/period-locks/review?period=2026-11&branch_id={$this->kdy->id}")->assertOk()
            ->assertJsonPath('files_removed', 1)->assertJsonPath('files_removed_self', 1);
    }

    public function test_ac39_missing_evidence_is_easy_to_find(): void
    {
        $a = $this->attach('r1.jpg');
        $this->attach('r2.jpg');
        $this->remove($this->csh, $this->kdy, $this->e['id'], $a['id'])->assertOk();
        $f = $this->expense('2026-11-11', '50.00', $this->csh);
        $g = $this->expense('2026-11-12', '60.00', $this->csh);
        $this->attach('g.jpg', txnId: $g['id']);

        $list = fn (string $files = '') => collect($this->accKdy()->getJson('/api/v1/transactions?per_page=200'.($files ? "&files={$files}" : ''))->assertOk()->json('data'));
        $row = $list()->firstWhere('id', $this->e['id']);
        $this->assertSame([1, 1], [$row['attachments_count'], $row['attachments_removed_count']]);

        $none = $list('none');
        $this->assertTrue($none->contains('id', $f['id']));
        $this->assertFalse($none->contains('id', $this->e['id']));
        $this->assertFalse($none->contains('id', $g['id']));
        $this->assertEmpty($none->whereNotIn('type', ['income', 'expense']), 'transfers and capital are not "missing evidence"');

        $this->assertSame([$this->e['id']], $list('removed')->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$this->e['id'], $g['id']], $list('with')->pluck('id')->all());
        $this->accKdy()->getJson('/api/v1/transactions?files=bogus')->assertStatus(422)->assertJsonValidationErrors('files');
    }

    public function test_ac40_same_receipt_on_two_entries_is_flagged_in_the_branch_only(): void
    {
        $inv = self::pdf();
        $this->attach('inv.pdf', $inv);
        $f = $this->expense('2026-11-11', '50.00', $this->csh);
        $res = $this->attach('inv.pdf', $inv, txnId: $f['id']);
        $this->assertSame([$this->e['ref_no']], $res['also_on']);
        $this->assertSame([$this->e['ref_no']], AuditLog::where('event', 'attachment.added')->latest('id')->first()->new_values['also_on']);
        $this->listing($this->csh, $this->kdy, $f['id'])->assertJsonPath('data.0.also_on', [$this->e['ref_no']]);
        $this->listing($this->csh, $this->kdy, $this->e['id'])->assertJsonPath('data.0.also_on', [$f['ref_no']]);

        // A GAL user uploading the same bytes to a GAL entry learns nothing about KDY.
        $g = $this->expense('2026-11-10', '40.00', $this->ba, $this->galCash);
        $gal = $this->attach('inv.pdf', $inv, $this->ba, $g['id'], $this->gal);
        $this->assertSame([], $gal['also_on']);
        $this->assertSame([], AuditLog::where('event', 'attachment.added')->latest('id')->first()->new_values['also_on']);
        $this->listing($this->ba, $this->gal, $g['id'])->assertJsonPath('data.0.also_on', []);
        // Only a Super Admin in All branches mode sees refs from other branches.
        $this->listing($this->admin, 'all', $g['id'])->assertJsonPath('data.0.also_on', [$this->e['ref_no'], $f['ref_no']]);
        $this->listing($this->admin, $this->gal, $g['id'])->assertJsonPath('data.0.also_on', []);

        // A removed copy no longer counts.
        $this->remove($this->acc, $this->kdy, $this->e['id'], $this->listing($this->acc, $this->kdy, $this->e['id'])->json('data.0.id'))->assertOk();
        $this->listing($this->csh, $this->kdy, $f['id'])->assertJsonPath('data.0.also_on', []);
    }

    public function test_ac41_attachments_verify(): void
    {
        $this->artisan('attachments:verify')->expectsOutputToContain('All 0 attachment file(s)')->assertExitCode(0);
        $a = $this->attach('r1.jpg');
        $b = $this->attach('r2.jpg');
        $this->attach('r3.jpg');
        $this->artisan('attachments:verify')->expectsOutputToContain('All 3 attachment file(s) are present and match their sha256.')->assertExitCode(0);

        $this->tamper($a['id'], null);
        $this->tamper($b['id'], self::jpeg(seed: 'swapped'));
        $this->artisan('attachments:verify')
            ->expectsTable(['Ref', 'Attachment', 'Name', 'State', 'Problem'], [
                [$this->e['ref_no'], $a['id'], 'r1.jpg', 'active', 'file missing'],
                [$this->e['ref_no'], $b['id'], 'r2.jpg', 'active', 'sha256 mismatch (file has '.hash('sha256', self::jpeg(seed: 'swapped')).')'],
            ])
            ->expectsOutputToContain('2 of 3 attachment file(s) are missing or do not match the uploaded file.')
            ->assertExitCode(1);
        // Read-only: no audit row and no attachment change.
        $this->assertSame(0, AuditLog::where('event', 'like', 'attachment.%')->where('event', '!=', 'attachment.added')->count());
    }
}
