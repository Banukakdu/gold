<?php

namespace Tests\Feature\FundReturn;

use App\Models\AuditLog;
use App\Support\Money;

/** Fund return ACs 6, 7, 9, 9a, 10, 11, 12 (G2, G5, R4, R6, R7). */
class LocksAndReversalTest extends FundReturnTestCase
{
    /** AC 6 (G2): only MAIN's month locked names MAIN. */
    public function test_ac6_main_lock_names_main(): void
    {
        $this->lock('2026-10', $this->main)->assertCreated();
        $before = $this->state();
        $this->fundReturn(date: '2026-10-15')->assertStatus(422)
            ->assertJsonPath('message', 'The period 2026-10 is locked for MAIN (head office side of this fund return).');
        $this->assertSame($before, $this->state());
    }

    /** AC 7: GAL's own lock keeps the plain message. */
    public function test_ac7_branch_lock_keeps_the_plain_message(): void
    {
        $this->lock('2026-10', $this->gal)->assertCreated();
        $before = $this->state();
        $this->fundReturn(date: '2026-10-15')->assertStatus(422)->assertJsonPath('message', 'The period 2026-10 is locked.');
        $this->assertSame($before, $this->state());
    }

    /** R4 ordering: with GAL and MAIN both locked, the sending branch's lock is reported first. */
    public function test_branch_lock_is_checked_before_main(): void
    {
        $this->lock('2026-10', $this->gal)->assertCreated();
        $this->lock('2026-10', $this->main)->assertCreated();
        $before = $this->state();
        $this->fundReturn(date: '2026-10-15')->assertStatus(422)->assertJsonPath('message', 'The period 2026-10 is locked.');
        $this->assertSame($before, $this->state());
    }

    /** AC 9: the Super Admin reverses; 4 mirrored lines, original reversed, balances and audit. */
    public function test_ac9_super_admin_reverses_a_fund_return(): void
    {
        $mainBefore = $this->bal($this->mainAcc);
        $t = $this->fundReturn()->assertCreated()->json();
        $r = $this->reverse($t['id'])->assertCreated()->json();

        $this->assertSame(['reversal', $t['id'], $this->gal->id], [$r['type'], $r['reversal_of_id'], $r['branch_id']]);
        $this->assertMatchesRegularExpression('/^GAL-REV-2026-\d{6}$/', $r['ref_no']);
        $orig = $this->lines($t['id'])->map(fn ($l) => [$l->account_id, $l->branch_id, $l->credit, $l->debit])->all();
        $mirror = $this->lines($r['id'])->map(fn ($l) => [$l->account_id, $l->branch_id, $l->debit, $l->credit])->all();
        $this->assertCount(4, $mirror);
        $this->assertEqualsCanonicalizing($orig, $mirror);
        $this->assertSame(['2026-11-18'], $this->lines($r['id'])->map(fn ($l) => $l->entry_date->format('Y-m-d'))->unique()->values()->all());
        $this->assertBalancedPerBranch($r['id']);

        $this->assertSame('reversed', $this->txn($t['id'])->status);
        $this->assertSame('40000.00', $this->bal($this->galCash));
        $this->assertSame($mainBefore, $this->bal($this->mainAcc));
        $this->assertSame(0, Money::toCents($this->ho($this->gal)->ledgerBalance()) + Money::toCents($this->bca($this->gal)->ledgerBalance()));

        $this->assertSame(1, AuditLog::where('event', 'transaction.fund_return')->where('auditable_id', $t['id'])->count());
        $this->assertSame(1, AuditLog::where('event', 'transaction.reversal')->where('auditable_id', $r['id'])->count());
        $rev = AuditLog::where('event', 'transaction.reversed')->where('auditable_id', $t['id'])->sole();
        $this->assertSame($this->gal->id, $rev->branch_id);
        $v = $this->sorted($rev->new_values);
        $this->assertSame(['reason', 'reversal_date', 'reversal_id', 'self', 'status'], array_keys($v));
        $this->assertSame(['Sent to the wrong account', '2026-11-18', $r['id'], 'reversed'], [$v['reason'], $v['reversal_date'], $v['reversal_id'], $v['status']]);
        $this->assertSame(['status' => 'posted'], $this->sorted($rev->old_values));
    }

    /** AC 9a (G5): a branch user with transfer.reverse may not reverse a fund return. */
    public function test_ac9a_branch_accountant_cannot_reverse(): void
    {
        $t = $this->fundReturn()->assertCreated()->json();
        foreach ([[$this->galAcc, $this->gal], [$this->ba, $this->gal]] as [$u, $b]) {
            $before = $this->state();
            $this->reverse($t['id'], $u, $b)->assertForbidden()->assertJsonPath('message', 'Only a Super Admin can reverse this entry.');
            $this->assertSame($before, $this->state());
        }
        $this->assertSame('posted', $this->txn($t['id'])->status);
    }

    /** AC 10: MAIN's user does not see the sending branch's row; the Super Admin (GAL selected) can reverse. */
    public function test_ac10_main_accountant_gets_404(): void
    {
        $t = $this->fundReturn()->assertCreated()->json();
        $before = $this->state();
        $this->reverse($t['id'], $this->mainAccUser, $this->main)->assertNotFound();
        $this->assertSame($before, $this->state());
        $this->reverse($t['id'], $this->admin, $this->gal)->assertCreated();
    }

    /** AC 11 / R6: MAIN's money account must still hold the amount. */
    public function test_ac11_reversal_needs_main_funds(): void
    {
        $t = $this->fundReturn()->assertCreated()->json();
        // Leave 10,000.00 in MAIN Bank.
        $spend = Money::fromCents(Money::toCents($this->bal($this->mainAcc)) - 1_000_000);
        $this->expense('2026-11-18', $spend, $this->admin, $this->mainAcc);
        $before = $this->state();
        $this->reverse($t['id'])->assertStatus(422)->assertJsonPath('message', "Insufficient balance in {$this->mainAcc->name} (available 10000.00).");
        $this->assertSame($before, $this->state());
        $this->assertSame('posted', $this->txn($t['id'])->status);
    }

    /** AC 12 (G2, MUST 3): the reversal names MAIN when only MAIN's month is locked; GAL's lock keeps the plain text. */
    public function test_ac12_reversal_main_lock_names_main(): void
    {
        $t = $this->fundReturn()->assertCreated()->json();
        $this->lock('2026-11', $this->main)->assertCreated();
        $before = $this->state();
        $this->reverse($t['id'])->assertStatus(422)
            ->assertJsonPath('message', 'The period 2026-11 is locked for MAIN (head office side of this fund return).');
        $this->assertSame($before, $this->state());

        $this->lock('2026-11', $this->gal)->assertCreated();
        $before = $this->state();
        $this->reverse($t['id'])->assertStatus(422)->assertJsonPath('message', 'The period 2026-11 is locked.');
        $this->assertSame($before, $this->state());
        $this->assertSame('posted', $this->txn($t['id'])->status);
    }
}
