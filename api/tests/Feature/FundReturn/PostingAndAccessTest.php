<?php

namespace Tests\Feature\FundReturn;

use App\Models\AuditLog;
use App\Models\Transaction;
use App\Support\Money;

/** Fund return ACs 1-5, 16 and 17 (G3, G4). */
class PostingAndAccessTest extends FundReturnTestCase
{
    /** AC 1: a branch Accountant returns funds; R2 postings asserted by account role, not by literal code. */
    public function test_ac1_branch_accountant_posts_a_fund_return(): void
    {
        $mainBefore = $this->bal($this->mainAcc);
        $t = $this->fundReturn()->assertCreated()->json();

        $this->assertSame('fund_return', $t['type']);
        $this->assertMatchesRegularExpression('/^GAL-RET-2026-\d{6}$/', $t['ref_no']);
        $this->assertSame([$this->gal->id, $this->main->id, '20000.00'], [$t['branch_id'], $t['related_branch_id'], $t['amount']]);

        $lines = $this->lines($t['id'])->map(fn ($l) => [$l->account_id, $l->branch_id, $l->debit, $l->credit])->all();
        $this->assertEqualsCanonicalizing([
            [$this->ho($this->gal)->id, $this->gal->id, '20000.00', '0.00'],
            [$this->galCash->id, $this->gal->id, '0.00', '20000.00'],
            [$this->mainAcc->id, $this->main->id, '20000.00', '0.00'],
            [$this->bca($this->gal)->id, $this->main->id, '0.00', '20000.00'],
        ], $lines);
        $this->assertBalancedPerBranch($t['id']);

        $this->assertSame('20000.00', $this->bal($this->galCash));
        $this->assertSame(Money::add($mainBefore, '20000.00'), $this->bal($this->mainAcc));

        // R7: one audit row, in the sending branch.
        $audit = AuditLog::where('event', 'transaction.fund_return')->sole();
        $this->assertSame([$this->gal->id, $this->galAcc->id, $t['id']], [$audit->branch_id, $audit->user_id, $audit->auditable_id]);
        $v = $this->sorted($audit->new_values);
        // AG-052 R12: the creation row of a between-branch transfer carries its receipt status (setting off: null).
        $this->assertSame(['account_id', 'amount', 'category_id', 'from_account_id', 'receipt_status', 'ref_no', 'to_account_id', 'txn_date'], array_keys($v));
        $this->assertNull($v['receipt_status']);
        $this->assertSame([$t['ref_no'], '20000.00', $this->galCash->id, $this->mainAcc->id], [$v['ref_no'], $v['amount'], $v['from_account_id'], $v['to_account_id']]);
    }

    /** AC 2: a Cashier has no transfer.create. */
    public function test_ac2_cashier_is_refused(): void
    {
        $before = $this->state();
        $this->fundReturn(by: $this->galCsh)->assertForbidden();
        $this->as($this->galCsh, $this->gal)->getJson('/api/v1/accounts/destinations')->assertForbidden();
        $this->assertSame($before, $this->state());
    }

    /** AC 3 / R8: a KDY Accountant cannot send from a GAL account. */
    public function test_ac3_other_branch_account_is_not_found(): void
    {
        $before = $this->state();
        $this->fundReturn(by: $this->acc, branch: $this->kdy)->assertNotFound();
        $this->assertSame($before, $this->state());
    }

    /** AC 4: the Super Admin in All branches mode returns funds for GAL. */
    public function test_ac4_super_admin_all_branches(): void
    {
        $t = $this->fundReturn('5000.00', by: $this->admin, branch: 'all')->assertCreated()->json();
        $this->assertSame(['fund_return', $this->gal->id], [$t['type'], $t['branch_id']]);
        $this->assertSame('35000.00', $this->bal($this->galCash));
    }

    /** AC 5 / R3: always blocked at the from account's balance; nothing written. */
    public function test_ac5_insufficient_balance(): void
    {
        $before = $this->state();
        $this->fundReturn('40000.01')->assertStatus(422)->assertJsonPath('message', 'Insufficient balance in GAL Cash (available 40,000.00).');
        $this->assertSame($before, $this->state());
        $this->assertSame(0, Transaction::withoutGlobalScopes()->where('type', 'fund_return')->count());
    }

    /** Accounting review decision: a return may exceed what Main allocated (no "allocated" cap). */
    public function test_return_is_not_capped_at_the_funds_allocated(): void
    {
        // GAL received 50,000.00 from Main; it adds 30,000.00 of its own takings and returns 60,000.00.
        $this->as($this->admin, $this->gal)->postJson('/api/v1/incomes', ['account_id' => $this->galCash->id,
            'category_id' => $this->category('income')->id, 'txn_date' => '2026-11-10', 'amount' => '30000.00'])->assertCreated();
        $t = $this->fundReturn('60000.00')->assertCreated()->json();
        $this->assertBalancedPerBranch($t['id']);
        $this->assertSame('10000.00', $this->bal($this->galCash));
        // The branch's HO current account moves to a debit (Main allocated 50,000.00, received back 60,000.00).
        $this->assertSame(1_000_000, Money::toCents($this->ho($this->gal)->ledgerBalance()));
    }

    /** AC 16: destinations carry branch.is_main. */
    public function test_ac16_destinations_have_is_main(): void
    {
        $rows = $this->galAccountant()->getJson('/api/v1/accounts/destinations')->assertOk()->json();
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $this->assertArrayHasKey('is_main', $r['branch']);
            $this->assertSame($r['branch']['id'] === $this->main->id, (bool) $r['branch']['is_main']);
        }
        $this->assertArrayNotHasKey('current_balance', $rows[0], 'destinations expose no balances');
    }

    /** AC 17 (G4 / R9): Main -> branch through /transfers is Super Admin only; MAIN internal stays allowed. */
    public function test_ac17_main_user_cannot_send_main_funds_to_a_branch(): void
    {
        $mainCash = $this->mainCash();
        $before = $this->state();
        $this->as($this->mainAccUser, $this->main)->postJson('/api/v1/transfers', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $this->kdyCash->id,
            'amount' => '1000.00', 'txn_date' => '2026-11-18', 'note' => 'Top-up'])
            ->assertForbidden()->assertJsonPath('message', 'Only a Super Admin can send funds from Main to a branch. Use "Fund a branch".');
        $this->assertSame($before, $this->state());

        $int = $this->as($this->mainAccUser, $this->main)->postJson('/api/v1/transfers', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $mainCash->id,
            'amount' => '1000.00', 'txn_date' => '2026-11-18', 'note' => 'Petty cash'])->assertCreated()->json();
        $this->assertSame('internal_transfer', $int['type']);

        $alc = $this->sa()->postJson('/api/v1/transfers', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $this->kdyCash->id,
            'amount' => '1000.00', 'txn_date' => '2026-11-18', 'note' => 'Top-up'])->assertCreated()->json();
        $this->assertSame('fund_allocation', $alc['type']);
    }
}
