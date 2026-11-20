<?php

namespace Tests\Feature\ExpenseTemplates;

use App\Models\AuditLog;
use App\Models\LedgerEntry;
use App\Models\RecurringTemplate;
use App\Models\Setting;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * AG-066 section 8: using a template (an ordinary POST /expenses with template_id) - AC 12-21, 23-29, 31, with the
 * accounting review's AC 17a (MUST 1), 28a (MUST 2) and 28b (SHOULD 6), and the SHOULD 5 audit filter.
 */
class UseTest extends ExpenseTemplateTestCase
{
    private function useAudit(int $txnId, string $event = 'transaction.expense'): AuditLog
    {
        return AuditLog::where('event', $event)->where('auditable_type', Transaction::class)->where('auditable_id', $txnId)->sole();
    }

    public function test_ac12_t_rent_is_held_above_the_limit_with_the_link_and_no_ledger_lines(): void
    {
        $rent = $this->tRent();
        $res = $this->useTpl($rent)->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('expense_template_id', $rent->id);
        $txn = $this->txn($res->json('id'));
        $this->assertSame(0, LedgerEntry::where('transaction_id', $txn->id)->count());
        $this->assertSame('400000.00', $this->bal($this->kdyBank));
        $a = $this->useAudit($txn->id, 'expense.approval_requested');
        $this->assertSame($rent->id, $a->new_values['template_id']);
        $this->assertSame('Rent - Kandy office', $a->new_values['template_name']);
        $this->assertSame([], $a->new_values['template_changes']);
        $this->assertSame(1, $a->new_values['template_version']);
        $this->assertFalse($a->new_values['template_changed_since_last_use']);
        $this->assertSame('INV-11', $txn->reference);
        // A plain expense still answers expense_template_id: null.
        $this->record('100.00', '2026-11-20')->assertCreated()->assertJsonPath('expense_template_id', null);
    }

    public function test_ac13_gal_template_posts_at_once_with_template_id_in_the_audit(): void
    {
        $this->sa()->postJson('/api/v1/capital', ['account_id' => $this->mainAcc->id, 'amount' => '1000000.00', 'txn_date' => '2026-11-01'])->assertCreated();
        $this->sa()->postJson('/api/v1/allocations', ['from_account_id' => $this->mainAcc->id, 'to_account_id' => $this->galCash->id,
            'amount' => '900000.00', 'txn_date' => '2026-11-01', 'kind' => 'top_up'])->assertCreated();
        $id = $this->createTpl($this->rentBody(['name' => 'Galle warehouse', 'account_id' => $this->galCash->id, 'amount' => '900000.00']), $this->galAcc, $this->gal)
            ->assertCreated()->json('id');
        $tpl = RecurringTemplate::withoutGlobalScopes()->findOrFail($id);
        $res = $this->useTpl($tpl, [], $this->galAcc)->assertCreated()->assertJsonPath('status', 'posted')->assertJsonPath('expense_template_id', $tpl->id);
        $a = $this->useAudit($res->json('id'));
        $this->assertSame($tpl->id, $a->new_values['template_id']);
        $this->assertSame($this->gal->id, (int) $a->branch_id);
        $this->assertSame('50000.00', $this->bal($this->galCash));
        $this->assertVerifies();
    }

    public function test_ac14_changes_from_the_template_are_recorded_and_shown_on_the_detail(): void
    {
        $rent = $this->tRent();
        $id = $this->useTpl($rent, ['amount' => '140000.00', 'payee_payer' => 'S. Fernando'])->assertCreated()->json('id');
        $a = $this->useAudit($id, 'expense.approval_requested');
        $this->assertSame(['amount', 'payee_payer'], $a->new_values['template_changes']);
        $this->assertSame(['amount' => '150000.00', 'payee_payer' => 'S. Fernando (landlord)'], self::sorted($a->new_values['template_values']));
        $this->as($this->acc, $this->kdy)->getJson("/api/v1/transactions/{$id}")->assertOk()
            ->assertJsonPath('expense_template.name', 'Rent - Kandy office')->assertJsonPath('expense_template.archived', false)
            ->assertJsonPath('template_changes', ['amount', 'payee_payer'])->assertJsonPath('template_values.amount', '150000.00')
            ->assertJsonPath('template_changed_since_last_use', false)->assertJsonPath('duplicate_confirmed', false);
        // A plain expense has no template.
        $plain = $this->record('100.00', '2026-11-20')->json('id');
        $this->as($this->acc, $this->kdy)->getJson("/api/v1/transactions/{$plain}")->assertOk()->assertJsonPath('expense_template', null);
    }

    public function test_ac15_second_use_in_the_month_is_a_409_until_confirmed(): void
    {
        $rent = $this->tRent();
        $first = $this->txn($this->useTpl($rent)->assertCreated()->json('id'));
        $this->at('2026-11-25');
        $before = [Transaction::withoutGlobalScopes()->count(), AuditLog::count()];
        $res = $this->useTpl($rent)->assertStatus(409);
        $this->assertSame("This template was already used for November 2026: {$first->ref_no} (Pending approval, 2026-11-20). Save anyway only if this is a separate bill.",
            $res->json('message'));
        $this->assertSame([['id' => $first->id, 'ref_no' => $first->ref_no, 'date' => '2026-11-20', 'status' => 'pending']], $res->json('duplicate_of'));
        $this->assertSame($before, [Transaction::withoutGlobalScopes()->count(), AuditLog::count()]);

        $id = $this->useTpl($rent, ['confirm_duplicate' => true])->assertCreated()->json('id');
        $a = $this->useAudit($id, 'expense.approval_requested');
        $this->assertTrue($a->new_values['duplicate_confirmed']);
        $this->assertSame([$first->ref_no], $a->new_values['duplicate_of']);
        $this->assertSame('November 2026', $a->new_values['duplicate_period']);

        // Posted (approved) counts too.
        $this->approve($first->id)->assertOk();
        $this->useTpl($rent)->assertStatus(409);
        // SHOULD 5: the audit filter "Duplicate template use confirmed".
        $rows = $this->accKdy()->getJson('/api/v1/audit-logs?'.http_build_query(['from' => '2026-11-01', 'to' => '2026-11-30', 'approval' => 'duplicate_confirmed']))
            ->assertOk()->json('data');
        $this->assertSame([$id], array_column($rows, 'auditable_id'));
    }

    public function test_ac16_void_rejected_cancelled_and_expired_uses_do_not_count(): void
    {
        $elec = $this->tElec();
        // Posted at once, then voided.
        $posted = $this->useTpl($elec, ['amount' => '1000.00'])->assertCreated()->assertJsonPath('status', 'posted')->json('id');
        $this->accKdy()->postJson("/api/v1/transactions/{$posted}/void", ['reason' => 'Entered twice'])->assertOk();
        // Held, then rejected; held, then cancelled; held, then expired.
        $rejected = $this->useTpl($elec, ['amount' => '60000.00'])->assertCreated()->assertJsonPath('status', 'pending')->json('id');
        $this->reject($rejected)->assertOk();
        $cancelled = $this->useTpl($elec, ['amount' => '60000.00'])->assertCreated()->json('id');
        $this->cancel($cancelled)->assertOk();
        $expired = $this->useTpl($elec, ['amount' => '60000.00'])->assertCreated()->json('id');
        DB::table('transactions')->where('id', $expired)->update(['status' => 'rejected', 'approval_status' => 'expired']);
        $this->useTpl($elec, ['amount' => '1000.00'])->assertCreated()->assertJsonPath('status', 'posted');
    }

    public function test_ac17_calendar_periods_by_frequency(): void
    {
        // Monthly: 2026-10-31 then 2026-11-01.
        $rent = $this->tRent(['amount' => '1000.00']);
        $this->useTpl($rent, ['txn_date' => '2026-10-31'])->assertCreated();
        $this->useTpl($rent, ['txn_date' => '2026-11-01'])->assertCreated();
        // Quarterly: 2026-10-05 then 2026-12-20 is the same quarter.
        $q = $this->tRent(['name' => 'Quarterly fee', 'amount' => '1000.00', 'frequency' => 'quarterly']);
        $this->useTpl($q, ['txn_date' => '2026-10-05'])->assertCreated();
        $this->at('2026-12-20');
        $this->useTpl($q, ['txn_date' => '2026-12-20'])->assertStatus(409)->assertJsonFragment(['date' => '2026-10-05']);
        $this->assertStringContainsString('already used for Q4 2026', $this->useTpl($q)->json('message'));
        // Quarterly: 2026-09-30 then 2026-10-01 are different quarters.
        $q2 = $this->tRent(['name' => 'Quarterly two', 'amount' => '1000.00', 'frequency' => 'quarterly']);
        $this->useTpl($q2, ['txn_date' => '2026-09-30'])->assertCreated();
        $this->useTpl($q2, ['txn_date' => '2026-10-01'])->assertCreated();
        // Yearly: the calendar year.
        $y = $this->tRent(['name' => 'Annual licence', 'amount' => '1000.00', 'frequency' => 'yearly']);
        $this->useTpl($y, ['txn_date' => '2026-01-15'])->assertCreated();
        $this->useTpl($y, ['txn_date' => '2026-12-20'])->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'already used for 2026:'));
        // No fixed period: never 409.
        $n = $this->tRent(['name' => 'Repairs', 'amount' => '1000.00', 'frequency' => 'none']);
        $this->useTpl($n)->assertCreated();
        $this->useTpl($n)->assertCreated();
        $this->useTpl($n, ['txn_date' => '2026-12-01'])->assertCreated();
    }

    /** Review MUST 1: the bill date (requested_date) decides the period; a reversed expense does not count. */
    public function test_ac17a_bill_date_and_reversed_expenses(): void
    {
        $rent = $this->tRent();
        $nov = $this->txn($this->useTpl($rent)->assertCreated()->assertJsonPath('status', 'pending')->json('id'));
        $this->at('2026-12-01');
        $this->approve($nov->id)->assertOk();
        $nov->refresh();
        $this->assertSame('2026-12-01', $nov->txn_date->format('Y-m-d'));
        $this->assertSame('2026-11-20', $nov->requested_date->format('Y-m-d'));
        // S1 on 2026-12-01: December with no transactions.
        $row = $this->rowOf($this->list($this->csh, $this->kdy), $rent);
        $this->assertSame('December 2026', $row['this_period']['label']);
        $this->assertSame([], $row['this_period']['transactions']);
        $this->assertSame('2026-11-20', $row['last_used_on']);
        // November (by bill date) is a duplicate; December is not.
        $this->useTpl($rent, ['txn_date' => '2026-11-28'])->assertStatus(409)->assertJsonPath('duplicate_of.0.ref_no', $nov->ref_no);
        $this->useTpl($rent, ['txn_date' => '2026-12-01'])->assertCreated();

        // A posted expense later reversed does not count.
        $elec = $this->tElec();
        $posted = $this->useTpl($elec, ['amount' => '1000.00'])->assertCreated()->assertJsonPath('status', 'posted')->json('id');
        $this->accKdy()->postJson("/api/v1/transactions/{$posted}/reverse", ['reason' => 'Wrong bill'])->assertCreated();
        $this->assertSame('reversed', $this->txn($posted)->status);
        $this->useTpl($elec, ['amount' => '1000.00'])->assertCreated();
    }

    public function test_ac18_unlinked_expenses_are_not_compared(): void
    {
        $rent = $this->tRent();
        $this->sa($this->kdy)->postJson('/api/v1/expenses', ['account_id' => $this->kdyBank->id, 'category_id' => $this->officeRent->id,
            'txn_date' => '2026-11-20', 'amount' => '150000.00', 'payee_payer' => 'S. Fernando (landlord)', 'payment_method' => 'bank_transfer',
            'description' => 'Monthly office rent'])->assertCreated();
        $this->useTpl($rent)->assertCreated();
    }

    /**
     * AC 19 "two parallel uses in an empty November: exactly one is created, the other gets 409". The tests run on one
     * connection, so the race is shown by its mechanism: the template row is read FOR UPDATE inside the posting
     * transaction before the duplicate read, which serialises two uses on MySQL; the second then sees the first.
     */
    public function test_ac19_parallel_uses_are_serialised_by_the_template_lock(): void
    {
        $rent = $this->tRent();
        DB::enableQueryLog();
        $statuses = [$this->useTpl($rent)->status(), $this->useTpl($rent)->status()];
        $this->assertSame([201, 409], $statuses);
        $this->assertSame(1, Transaction::withoutGlobalScopes()->where('expense_template_id', $rent->id)->count());
        $log = array_column(DB::getQueryLog(), 'query');
        $lock = array_keys(array_filter($log, fn ($q) => str_contains($q, 'recurring_templates') && str_contains($q, 'where')
            && (DB::connection()->getDriverName() !== 'mysql' || str_contains($q, 'for update'))));
        $insert = array_keys(array_filter($log, fn ($q) => str_starts_with(strtolower($q), 'insert into') && str_contains($q, 'transactions')));
        $this->assertNotEmpty($lock);
        $this->assertLessThan($insert[0], $lock[0], 'the template is locked before the expense row is written');
    }

    public function test_ac20_archived_template_cannot_be_used_and_ac21_links_stay(): void
    {
        $rent = $this->tRent();
        $first = $this->useTpl($rent)->assertCreated()->json('id');
        $this->accKdy()->postJson("/api/v1/expense-templates/{$rent->id}/archive", ['reason' => 'Lease ended'])->assertOk();
        $count = Transaction::withoutGlobalScopes()->count();
        $this->at('2026-12-01');
        $this->useTpl($rent)->assertUnprocessable()->assertJsonPath('message', 'This template is archived.');
        $this->assertSame($count, Transaction::withoutGlobalScopes()->count());
        $this->assertSame($rent->id, (int) $this->txn($first)->expense_template_id);
        $this->as($this->acc, $this->kdy)->getJson("/api/v1/transactions/{$first}")->assertJsonPath('expense_template.archived', true);
        $this->accKdy()->postJson("/api/v1/expense-templates/{$rent->id}/restore")->assertOk();
        $this->useTpl($rent)->assertCreated();
    }

    public function test_ac23_inactive_account_after_saving_the_template(): void
    {
        $rent = $this->tRent();
        DB::table('accounts')->where('id', $this->kdyBank->id)->update(['status' => 'inactive']);
        $row = $this->rowOf($this->list($this->csh, $this->kdy), $rent);
        $this->assertSame('inactive', $row['account']['status']);
        $count = Transaction::withoutGlobalScopes()->count();
        $this->useTpl($rent)->assertUnprocessable()->assertJsonPath('message', 'Account is inactive.');
        $this->assertSame($count, Transaction::withoutGlobalScopes()->count());
        $id = $this->useTpl($rent, ['account_id' => $this->kdyCash->id])->assertCreated()->json('id');
        $a = $this->useAudit($id, 'expense.approval_requested')->new_values;
        $this->assertSame(['account_id'], $a['template_changes']);
        $this->assertSame(['account_id' => $this->kdyBank->id], $a['template_values']);
        $this->assertSame(['account_id' => 'Bank'], $a['template_labels']);
    }

    public function test_ac24_inactive_category_is_refused_by_the_ledger(): void
    {
        $rent = $this->tRent();
        $this->officeRent->update(['is_active' => false]);
        $this->assertFalse($this->rowOf($this->list($this->csh, $this->kdy), $rent)['category']['active']);
        $this->useTpl($rent)->assertUnprocessable()->assertJsonPath('message', 'Category must be an active expense category.');
        $this->officeRent->update(['is_active' => true]);
        $this->rentParent->update(['is_active' => false]);
        $this->useTpl($rent)->assertUnprocessable()->assertJsonPath('message', 'Category must be an active expense category.');
    }

    public function test_ac25_locked_period_refuses_the_expense(): void
    {
        $rent = $this->tRent();
        $this->lock('2026-11', $this->kdy)->assertCreated();
        $this->useTpl($rent)->assertUnprocessable()->assertJsonPath('message', 'The period 2026-11 is locked.');
        $this->assertSame(0, Transaction::withoutGlobalScopes()->where('expense_template_id', $rent->id)->count());
    }

    public function test_ac26_block_mode_funds_check_applies(): void
    {
        $this->assertSame('block', Setting::find('expense_negative_balance')->value);
        $elec = $this->tElec();
        $this->sa($this->kdy)->postJson('/api/v1/expenses', ['account_id' => $this->kdyCash->id, 'category_id' => $this->water->id,
            'txn_date' => '2026-11-20', 'amount' => '195000.00'])->assertCreated();
        $this->assertSame('5000.00', $this->bal($this->kdyCash));
        $count = Transaction::withoutGlobalScopes()->count();
        $this->useTpl($elec, ['amount' => '12340.00'], $this->admin, $this->kdy)->assertUnprocessable()
            ->assertJsonPath('message', 'Insufficient balance in KDY Cash (available 5,000.00).');
        $this->assertSame($count, Transaction::withoutGlobalScopes()->count());
    }

    public function test_ac27_same_day_rule_unchanged(): void
    {
        $elec = $this->tElec();
        $this->record('30000.00', '2026-11-20')->assertCreated()->assertJsonPath('status', 'posted');
        $this->useTpl($elec, ['amount' => '30000.00'])->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('hold_reason', 'same_day_total');
    }

    public function test_ac28_editing_the_template_never_changes_a_pending_request(): void
    {
        $rent = $this->tRent();
        $id = $this->useTpl($rent)->assertCreated()->json('id');
        $this->accKdy()->putJson("/api/v1/expense-templates/{$rent->id}", $this->rentBody(['amount' => '160000.00']))->assertOk();
        $this->assertSame('150000.00', (string) $this->txn($id)->amount);
        $this->approve($id)->assertOk();
        $this->assertSame('150000.00', (string) $this->txn($id)->amount);
        $this->assertSame('250000.00', $this->bal($this->kdyBank));
        $this->assertSame($rent->id, (int) $this->txn($id)->expense_template_id);
    }

    /** Review MUST 2: versions, changes with the template's values, and a confirmed duplicate reach the approver. */
    public function test_ac28a_the_approver_sees_the_template_facts(): void
    {
        $rent = $this->tRent();
        $first = $this->txn($this->useTpl($rent)->assertCreated()->json('id'));
        $this->accKdy()->putJson("/api/v1/expense-templates/{$rent->id}", $this->rentBody(['payee_payer' => 'K. Perera (new landlord)']))->assertOk()
            ->assertJsonPath('version', 2);
        // S2: the list says the template changed since its last use.
        $row = $this->rowOf($this->list($this->csh, $this->kdy), $rent);
        $this->assertTrue($row['changed_since_last_use']);
        $this->assertSame('2026-11-20', $row['last_changed']['on']);
        $this->assertSame($this->acc->name, $row['last_changed']['by']['name']);

        $second = $this->txn($this->useTpl($rent, ['confirm_duplicate' => true])->assertCreated()->json('id'));
        $a = $this->useAudit($second->id, 'expense.approval_requested')->new_values;
        $this->assertSame(2, $a['template_version']);
        $this->assertTrue($a['template_changed_since_last_use']);
        $this->assertSame([], $a['template_changes']);
        $this->assertSame(1, $a['template_previous_version']);
        $this->assertSame($this->acc->name, $a['template_changed_by']);
        $this->assertTrue($a['duplicate_confirmed']);
        $this->assertSame([$first->ref_no], $a['duplicate_of']);

        $widget = $this->widget($this->kdyBa, $this->kdy);
        $r = collect($widget['to_approve']['data'])->firstWhere('id', $second->id);
        $this->assertSame(['id' => $rent->id, 'name' => 'Rent - Kandy office', 'version' => 2], $r['template']);
        $this->assertTrue($r['template_changed_since_last_use']);
        $this->assertTrue($r['duplicate_confirmed']);
        $this->assertSame([$first->ref_no], $r['duplicate_of']);
        $this->assertSame('November 2026', $r['duplicate_period']);
        $this->assertSame($first->ref_no, $r['duplicate_entries'][0]['ref_no']);
        $this->assertFalse(collect($widget['to_approve']['data'])->firstWhere('id', $first->id)['template_changed_since_last_use']);

        // A later use with no template change in between: false. With 140,000.00: the change and the template's value.
        $this->at('2026-12-01');
        $third = $this->useTpl($rent, ['amount' => '140000.00'])->assertCreated()->json('id');
        $a = $this->useAudit($third, 'expense.approval_requested')->new_values;
        $this->assertFalse($a['template_changed_since_last_use']);
        $this->assertSame(['amount'], $a['template_changes']);
        $this->assertSame('150000.00', $a['template_values']['amount']);
        $r = collect($this->widget($this->kdyBa, $this->kdy)['to_approve']['data'])->firstWhere('id', $third);
        $this->assertSame(['amount'], $r['template_changes']);
        $this->assertSame('150000.00', $r['template_values']['amount']);
        $this->assertFalse($r['duplicate_confirmed']);
        // A row without a template.
        $plain = $this->e1();
        $r = collect($this->widget($this->kdyBa, $this->kdy)['to_approve']['data'])->firstWhere('id', $plain->id);
        $this->assertNull($r['template']);
        $this->assertSame([], $r['template_changes']);
    }

    /** Review SHOULD 6: the link is fixed at creation. */
    public function test_ac28b_the_link_never_changes_and_contras_and_reversals_do_not_carry_it(): void
    {
        $elec = $this->tElec();
        $other = $this->tRent(['amount' => '1000.00']);
        $id = $this->useTpl($elec, ['amount' => '1000.00'])->assertCreated()->assertJsonPath('status', 'posted')->json('id');
        $this->accKdy()->putJson("/api/v1/transactions/{$id}", ['expense_template_id' => $other->id, 'template_id' => $other->id, 'payee_payer' => 'CEB'])->assertOk();
        $this->assertSame($elec->id, (int) $this->txn($id)->expense_template_id);
        $this->accKdy()->putJson("/api/v1/transactions/{$id}", ['category_id' => $this->water->id])->assertOk(); // reclassify
        $this->assertSame($elec->id, (int) $this->txn($id)->expense_template_id);
        $this->accKdy()->postJson("/api/v1/transactions/{$id}/reverse", ['reason' => 'Wrong bill'])->assertCreated();
        $this->assertSame($elec->id, (int) $this->txn($id)->expense_template_id);
        $reversal = Transaction::withoutGlobalScopes()->where('reversal_of_id', $id)->sole();
        $this->assertNull($reversal->expense_template_id);

        $void = $this->useTpl($elec, ['amount' => '500.00'])->assertCreated()->json('id');
        $this->accKdy()->postJson("/api/v1/transactions/{$void}/void", ['reason' => 'Entered twice'])->assertOk();
        $this->assertSame($elec->id, (int) $this->txn($void)->expense_template_id);
        // The void contras are lines of the same entry; no other transaction row carries the link.
        $this->assertSame([$id, $void], Transaction::withoutGlobalScopes()->whereNotNull('expense_template_id')->orderBy('id')->pluck('id')->all());
    }

    public function test_ac29_this_period_last_used_and_threshold_on_s1(): void
    {
        $rent = $this->tRent();
        $elec = $this->tElec();
        $none = $this->tRent(['name' => 'Repairs', 'frequency' => 'none', 'amount' => '100.00']);
        $id = $this->useTpl($rent)->assertCreated()->json('id');
        $list = $this->list($this->csh, $this->kdy);
        $this->assertSame('50000.00', $list['threshold']);
        $r = $this->rowOf($list, $rent);
        $this->assertSame('November 2026', $r['this_period']['label']);
        $this->assertSame([['id' => $id, 'ref_no' => $this->txn($id)->ref_no, 'date' => '2026-11-20', 'status' => 'pending', 'status_label' => 'Pending approval']],
            $r['this_period']['transactions']);
        $this->assertSame('2026-11-20', $r['last_used_on']);
        $this->assertSame([], $this->rowOf($list, $elec)['this_period']['transactions']);
        $this->assertNull($this->rowOf($list, $elec)['last_used_on']);
        $this->assertNull($this->rowOf($list, $none)['this_period']);
        $this->assertSame('150000.00', $r['amount']); // above the threshold: S1 tags "Needs approval"
        $this->assertSame(['Electricity - Kandy', 'Rent - Kandy office', 'Repairs'], array_column($list['data'], 'name'));
    }

    public function test_ac31_balances_change_only_through_postings_and_the_ledger_verifies(): void
    {
        $rent = $this->tRent();
        $elec = $this->tElec();
        $id = $this->useTpl($rent)->assertCreated()->json('id');
        $this->useTpl($elec, ['amount' => '12340.00'])->assertCreated()->assertJsonPath('status', 'posted');
        $this->assertSame('187660.00', $this->bal($this->kdyCash));
        $this->accKdy()->putJson("/api/v1/expense-templates/{$elec->id}", $this->elecBody(['amount' => '1.00']))->assertOk();
        $this->accKdy()->postJson("/api/v1/expense-templates/{$elec->id}/archive", [])->assertOk();
        $this->assertSame('187660.00', $this->bal($this->kdyCash));
        $this->approve($id)->assertOk();
        $this->assertSame('250000.00', $this->bal($this->kdyBank));
        $this->assertVerifies();
    }

    public function test_income_ignores_template_fields(): void
    {
        $rent = $this->tRent();
        $res = $this->as($this->acc, $this->kdy)->postJson('/api/v1/incomes', ['account_id' => $this->kdyCash->id,
            'category_id' => $this->category('income')->id, 'txn_date' => '2026-11-20', 'amount' => '10.00', 'template_id' => $rent->id])->assertCreated();
        $this->assertNull($this->txn($res->json('id'))->expense_template_id);
    }
}
