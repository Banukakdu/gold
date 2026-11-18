<?php

namespace Tests\Feature\Attachments;

use App\Exceptions\ImmutableRecordException;
use App\Models\Attachment;
use App\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** AG-062 locked month, void and removal: attachments spec AC 18-23 + accounting review AC 34, 36, 37. */
class RemovalTest extends AttachmentsTestCase
{
    private function report(): array
    {
        return $this->sa($this->kdy)->getJson('/api/v1/reports/income-expense?from=2026-10-01&to=2026-10-31')->assertOk()->json();
    }

    public function test_ac18_ac37_adding_to_a_locked_month_changes_no_figure_and_is_marked(): void
    {
        $o = $this->expense('2026-10-20', '300.00', $this->csh);
        $this->lock('2026-10', $this->kdy)->assertCreated();
        $before = [$this->snapshot(), $this->report()];

        $a = $this->attach('late-invoice.pdf', self::pdf(4096, 'late'), txnId: $o['id']);
        $this->assertSame('close', $a['added_after']);
        $this->assertFalse($a['can_remove']);
        $this->assertSame([$this->snapshot(), $this->report()], $before, 'balances, ledger_entries and the October report unchanged');

        $audit = AuditLog::where('event', 'attachment.added')->sole();
        $this->assertTrue($audit->new_values['period_locked']);
        $this->assertSame('close', $audit->new_values['added_after']);
        $this->assertSame('posted', $audit->new_values['txn_status']);
        $this->assertSame('close', Attachment::find($a['id'])->added_after);

        $this->listing($this->csh, $this->kdy, $o['id'])->assertJsonPath('note', 'October 2026 is locked: files can be added as evidence but not removed.')
            ->assertJsonPath('data.0.added_after', 'close');
        // A November entry in the open month: nothing is marked.
        $this->assertNull($this->attach('r1.jpg')['added_after']);
        $this->listing($this->csh, $this->kdy, $this->e['id'])->assertJsonPath('note', null);
    }

    public function test_ac19_no_removal_in_a_locked_month(): void
    {
        $o = $this->expense('2026-10-20', '300.00', $this->csh);
        $a = $this->attach('r1.jpg', txnId: $o['id']);
        $this->lock('2026-10', $this->kdy)->assertCreated();
        $row = Attachment::find($a['id'])->getAttributes();

        $this->remove($this->acc, $this->kdy, $o['id'], $a['id'])->assertStatus(422)->assertJsonPath('message', 'The period 2026-10 is locked.');
        $this->remove($this->csh, $this->kdy, $o['id'], $a['id'])->assertStatus(422)->assertJsonPath('message', 'The period 2026-10 is locked.');
        $this->assertSame($row, Attachment::find($a['id'])->getAttributes());
        $this->assertSame(0, AuditLog::where('event', 'attachment.removed')->count());
    }

    public function test_ac36_cumulative_lock_uses_the_entry_date(): void
    {
        $o = $this->expense('2026-10-20', '300.00', $this->csh);
        $a = $this->attach('r1.jpg', txnId: $o['id']);
        $nov = $this->attach('r2.jpg'); // E is dated 2026-11-10
        $this->lock('2026-11', $this->kdy)->assertCreated(); // only November locked: closes October too

        $this->remove($this->acc, $this->kdy, $o['id'], $a['id'])->assertStatus(422)->assertJsonPath('message', 'The period 2026-10 is locked.');
        $this->remove($this->acc, $this->kdy, $this->e['id'], $nov['id'])->assertStatus(422)->assertJsonPath('message', 'The period 2026-11 is locked.');
        // A global lock counts too, and a GAL lock does not.
        $this->unlock($this->activeLock('2026-11', $this->kdy))->assertOk();
        $this->lock('2026-10', $this->gal)->assertCreated();
        $this->remove($this->acc, $this->kdy, $o['id'], $a['id'])->assertOk();
        $this->lock('2026-11', null)->assertCreated();
        $this->remove($this->acc, $this->kdy, $this->e['id'], $nov['id'])->assertStatus(422)->assertJsonPath('message', 'The period 2026-11 is locked.');
    }

    public function test_ac20_ac37_void_and_reversed_entries_take_files_but_never_lose_them(): void
    {
        $a = $this->attach('r1.jpg');
        $this->accKdy()->postJson("/api/v1/transactions/{$this->e['id']}/void", ['reason' => 'Duplicate'])->assertOk();
        $b = $this->attach('duplicate-bill.pdf', self::pdf(4096, 'dup'), $this->acc);
        $this->assertSame('void', $b['added_after']);
        $this->assertSame('void', AuditLog::where('event', 'attachment.added')->latest('id')->first()->new_values['txn_status']);
        $this->assertSame('void', AuditLog::where('event', 'attachment.added')->latest('id')->first()->new_values['added_after']);
        foreach ([$a, $b] as $x) {
            $this->remove($this->acc, $this->kdy, $this->e['id'], $x['id'])
                ->assertStatus(422)->assertJsonPath('message', 'Files of a voided or reversed entry cannot be removed.');
        }
        $this->listing($this->acc, $this->kdy, $this->e['id'])->assertJsonPath('note', 'This entry is void: files can be added but not removed.')
            ->assertJsonPath('data.0.can_remove', false);

        $r = $this->expense('2026-11-12', '20.00');
        $c = $this->attach('bill.jpg', self::jpeg(seed: 'bill'), $this->acc, $r['id']);
        $this->accKdy()->postJson("/api/v1/transactions/{$r['id']}/reverse", ['reason' => 'Returned'])->assertCreated();
        $credit = $this->attach('credit-note.pdf', self::pdf(4096, 'cn'), $this->acc, $r['id']);
        $this->assertSame('reversal', $credit['added_after']);
        $this->assertSame('reversed', AuditLog::where('event', 'attachment.added')->latest('id')->first()->new_values['txn_status']);
        $this->remove($this->acc, $this->kdy, $r['id'], $c['id'])
            ->assertStatus(422)->assertJsonPath('message', 'Files of a voided or reversed entry cannot be removed.');
        $this->assertSame(0, Attachment::whereNotNull('removed_at')->count());
    }

    public function test_ac21_soft_removal_with_reason(): void
    {
        $a1 = $this->attach('r1.jpg');
        $countBefore = $this->accKdy()->getJson("/api/v1/transactions/{$this->e['id']}")->json('attachments_count');

        $this->remove($this->acc, $this->kdy, $this->e['id'], $a1['id'], 'ab')->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->remove($this->acc, $this->kdy, $this->e['id'], $a1['id'], str_repeat('x', 501))->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($this->acc, $this->kdy)->postJson("/api/v1/transactions/{$this->e['id']}/attachments/{$a1['id']}/remove", [])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $res = $this->remove($this->acc, $this->kdy, $this->e['id'], $a1['id'], 'Wrong bill')->assertOk();
        $res->assertJsonPath('removed_by', ['id' => $this->acc->id, 'name' => $this->acc->name])->assertJsonPath('removal_reason', 'Wrong bill');
        $row = Attachment::find($a1['id']);
        $this->assertNotNull($row->removed_at);
        $this->assertSame([$this->acc->id, 'Wrong bill'], [$row->removed_by, $row->removal_reason]);
        $this->assertTrue($this->disk()->exists($row->path), 'the file stays on disk');

        $this->listing($this->acc, $this->kdy, $this->e['id'])->assertJsonCount(0, 'data')->assertJsonPath('removed_count', 1);
        $this->listing($this->acc, $this->kdy, $this->e['id'], removed: true)->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.removal_reason', 'Wrong bill');
        $this->assertSame($countBefore - 1, $this->accKdy()->getJson("/api/v1/transactions/{$this->e['id']}")->json('attachments_count'));

        $audit = AuditLog::where('event', 'attachment.removed')->sole();
        $this->assertSame([$this->kdy->id, $this->e['id']], [$audit->branch_id, $audit->auditable_id]);
        $this->assertEquals(['attachment_id' => $a1['id'], 'original_name' => 'r1.jpg', 'sha256' => $a1['sha256'],
            'uploaded_by' => $this->csh->id, 'uploaded_at' => $a1['created_at'], 'added_after' => null], $audit->old_values);
        $this->assertEquals(['reason' => 'Wrong bill', 'removed_at' => $row->removed_at->toIso8601String(), 'self' => false, 'txn_status' => 'posted'], $audit->new_values);

        $this->remove($this->acc, $this->kdy, $this->e['id'], $a1['id'])->assertStatus(422)->assertJsonPath('message', 'This file has already been removed.');
        $this->assertSame(1, AuditLog::where('event', 'attachment.removed')->count());

        // The uploader removing their own file is audited with self: true.
        $a2 = $this->attach('r2.jpg');
        $this->remove($this->csh, $this->kdy, $this->e['id'], $a2['id'], 'Blurred photo')->assertOk();
        $this->assertTrue(AuditLog::where('event', 'attachment.removed')->latest('id')->first()->new_values['self']);
    }

    public function test_ac22_no_route_deletes_and_the_model_refuses_changes(): void
    {
        $a1 = $this->attach('r1.jpg');
        foreach (["/api/v1/transactions/{$this->e['id']}/attachments/{$a1['id']}", "/api/v1/transactions/{$this->e['id']}/attachments/{$a1['id']}/download",
            "/api/v1/transactions/{$this->e['id']}/attachments"] as $url) {
            $this->assertContains($this->sa()->deleteJson($url)->status(), [404, 405]);
            $this->assertContains($this->sa()->putJson($url, ['original_name' => 'x.pdf'])->status(), [404, 405]);
        }
        $model = Attachment::find($a1['id']);
        try {
            $model->delete();
            $this->fail('delete() did not throw');
        } catch (ImmutableRecordException) {
            $this->addToAssertionCount(1);
        }
        foreach (['original_name' => 'x.jpg', 'sha256' => str_repeat('0', 64), 'path' => 'x', 'transaction_id' => 1] as $col => $v) {
            try {
                Attachment::find($a1['id'])->update([$col => $v]);
                $this->fail("update of {$col} did not throw");
            } catch (ImmutableRecordException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->remove($this->acc, $this->kdy, $this->e['id'], $a1['id'])->assertOk();
        try {
            Attachment::find($a1['id'])->update(['removal_reason' => 'Changed my mind']);
            $this->fail('second removal update did not throw');
        } catch (ImmutableRecordException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('Wrong bill', Attachment::find($a1['id'])->removal_reason);
        $this->assertSame(1, Attachment::count());
    }

    public function test_ac34_database_triggers_refuse_raw_changes(): void
    {
        $a1 = $this->attach('r1.jpg');
        $f = $this->expense('2026-11-11', '10.00', $this->csh);
        $before = fn () => (array) DB::table('attachments')->where('id', $a1['id'])->first();
        $row = $before();
        $raw = [
            'delete one' => fn () => DB::table('attachments')->where('id', $a1['id'])->delete(),
            'delete all' => fn () => DB::table('attachments')->delete(),
            'sha256' => fn () => DB::table('attachments')->where('id', $a1['id'])->update(['sha256' => str_repeat('0', 64)]),
            'path' => fn () => DB::table('attachments')->where('id', $a1['id'])->update(['path' => 'elsewhere.jpg']),
            'transaction_id' => fn () => DB::table('attachments')->where('id', $a1['id'])->update(['transaction_id' => $f['id']]),
            'added_after' => fn () => DB::table('attachments')->where('id', $a1['id'])->update(['added_after' => 'close']),
            // An update that is not a removal (removed_at stays null).
            'reason only' => fn () => DB::table('attachments')->where('id', $a1['id'])->update(['removal_reason' => 'x']),
            // A removal that also rewrites a frozen column.
            'removal + name' => fn () => DB::table('attachments')->where('id', $a1['id'])
                ->update(['removed_at' => now(), 'removed_by' => $this->acc->id, 'removal_reason' => 'x', 'original_name' => 'y.jpg']),
        ];
        $this->assertRefused($raw);
        $this->assertEquals($row, $before(), 'row unchanged');

        // The one-time removal itself is allowed at database level...
        $this->remove($this->acc, $this->kdy, $this->e['id'], $a1['id'])->assertOk();
        $removed = $before();
        // ...but never undone or rewritten.
        $this->assertRefused([
            'reason again' => fn () => DB::table('attachments')->where('id', $a1['id'])->update(['removal_reason' => 'Rewritten']),
            'undo' => fn () => DB::table('attachments')->where('id', $a1['id'])->update(['removed_at' => null, 'removed_by' => null]),
            'remover' => fn () => DB::table('attachments')->where('id', $a1['id'])->update(['removed_by' => $this->csh->id]),
            'delete' => fn () => DB::table('attachments')->delete(),
        ]);
        $this->assertEquals($removed, $before());
        // The entry itself cannot be deleted while it has attachments (FK restrict).
        $this->expectException(QueryException::class);
        DB::table('transactions')->where('id', $this->e['id'])->delete();
    }

    private function assertRefused(array $calls): void
    {
        foreach ($calls as $label => $call) {
            try {
                $call();
                $this->fail("raw write '{$label}' did not throw");
            } catch (QueryException $e) {
                $this->assertStringContainsString('attachments rows are immutable', $e->getMessage(), $label);
            }
        }
    }

    public function test_ac23_removed_files_only_for_auditors(): void
    {
        $a1 = $this->attach('r1.jpg');
        $this->remove($this->acc, $this->kdy, $this->e['id'], $a1['id'])->assertOk();
        $this->download($this->vw, $this->kdy, $this->e['id'], $a1['id'])->assertOk();
        $this->download($this->acc, $this->kdy, $this->e['id'], $a1['id'])->assertOk();
        $this->download($this->csh, $this->kdy, $this->e['id'], $a1['id'])
            ->assertForbidden()->assertJsonPath('message', 'Only auditors can download removed files.');
        // Metadata and reason are visible to every transaction.view user (Q4).
        $this->listing($this->csh, $this->kdy, $this->e['id'], removed: true)->assertJsonPath('data.0.removal_reason', 'Wrong bill')
            ->assertJsonPath('can_download_removed', false);
        $this->listing($this->vw, $this->kdy, $this->e['id'], removed: true)->assertJsonPath('can_download_removed', true);
        $this->assertSame([true, true], AuditLog::where('event', 'attachment.downloaded')->pluck('new_values')->pluck('removed')->all());
    }
}
