<?php

namespace Tests\Feature\ExpenseTemplates;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\LedgerEntry;
use App\Models\RecurringTemplate;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/** AG-066 section 8: AC 1-9, 20-22, 24-25 (template side), 30. Templates post nothing (R1). */
class MaintenanceTest extends ExpenseTemplateTestCase
{
    private function books(): array
    {
        return [
            'transactions' => Transaction::withoutGlobalScopes()->count(),
            'entries' => LedgerEntry::count(),
            'balances' => Account::withoutGlobalScopes()->orderBy('id')->pluck('current_balance', 'id')->all(),
        ];
    }

    public function test_ac1_accountant_creates_t_rent_without_touching_the_books(): void
    {
        $before = $this->books();
        $res = $this->createTpl($this->rentBody())->assertCreated()
            ->assertJsonPath('name', 'Rent - Kandy office')->assertJsonPath('amount', '150000.00')
            ->assertJsonPath('branch.code', 'KDY')->assertJsonPath('version', 1)->assertJsonPath('frequency', 'monthly')
            ->assertJsonPath('this_period.label', 'November 2026')->assertJsonPath('this_period.transactions', [])
            ->assertJsonPath('last_used_on', null)->assertJsonPath('can_edit', true)->assertJsonPath('can_use', true);
        $tpl = RecurringTemplate::withoutGlobalScopes()->findOrFail($res->json('id'));
        $this->assertSame(15000000, $tpl->amount_cents);
        $this->assertSame($this->kdy->id, (int) $tpl->branch_id);
        $this->assertSame($this->acc->id, (int) $tpl->created_by);
        $rows = $this->templateAudits($tpl);
        $this->assertCount(1, $rows);
        $this->assertSame('expense_template.created', $rows[0]->event);
        $this->assertSame($this->kdy->id, (int) $rows[0]->branch_id);
        $this->assertSame('150000.00', $rows[0]->new_values['amount']);
        $this->assertSame('Rent - Kandy office', $rows[0]->new_values['name']);
        $this->assertSame($before, $this->books());
    }

    public function test_ac2_cashier_cannot_maintain_templates(): void
    {
        $tpl = $this->tRent();
        $audits = AuditLog::count();
        $kdy = $this->as($this->csh, $this->kdy);
        $kdy->postJson('/api/v1/expense-templates', $this->rentBody(['name' => 'Other']))->assertForbidden()->assertJsonPath('message', 'Missing permission: expense.edit');
        $kdy->putJson("/api/v1/expense-templates/{$tpl->id}", $this->rentBody(['amount' => '1.00']))->assertForbidden()->assertJsonPath('message', 'Missing permission: expense.edit');
        $kdy->postJson("/api/v1/expense-templates/{$tpl->id}/archive", [])->assertForbidden()->assertJsonPath('message', 'Missing permission: expense.edit');
        $kdy->postJson("/api/v1/expense-templates/{$tpl->id}/restore", [])->assertForbidden()->assertJsonPath('message', 'Missing permission: expense.edit');
        $this->assertSame($audits, AuditLog::count());
        $this->assertSame(1, RecurringTemplate::withoutGlobalScopes()->count());
    }

    public function test_ac3_cashier_lists_kdy_templates_only_and_the_viewer_is_refused(): void
    {
        $rent = $this->tRent();
        $gal = $this->createTpl($this->rentBody(['account_id' => $this->galCash->id]), $this->galAcc, $this->gal)->assertCreated()->json('id');
        $list = $this->list($this->csh, $this->kdy);
        $this->assertSame([$rent->id], array_column($list['data'], 'id'));
        $this->assertNotContains($gal, array_column($list['data'], 'id'));
        $this->assertTrue($list['data'][0]['can_use']);
        $this->assertFalse($list['data'][0]['can_edit']);
        $this->as($this->csh, $this->kdy)->getJson("/api/v1/expense-templates/{$rent->id}")->assertOk()->assertJsonPath('id', $rent->id);
        $this->as($this->vw, $this->kdy)->getJson('/api/v1/expense-templates')->assertForbidden()->assertJsonPath('message', 'Missing permission: expense.create');
        $this->as($this->vw, $this->kdy)->getJson("/api/v1/expense-templates/{$rent->id}")->assertForbidden();
    }

    public function test_ac4_name_is_unique_among_active_templates_case_insensitively(): void
    {
        $first = $this->tRent();
        $this->createTpl($this->rentBody(['name' => ' rent -  KANDY office ']))->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'A template named rent - KANDY office already exists in this branch.');
        // The same name in GAL is accepted.
        $this->createTpl($this->rentBody(['account_id' => $this->galCash->id]), $this->galAcc, $this->gal)->assertCreated();
        // After the first is archived, the new one is accepted.
        $this->accKdy()->postJson("/api/v1/expense-templates/{$first->id}/archive", [])->assertOk();
        $this->createTpl($this->rentBody(['name' => ' rent -  KANDY office ']))->assertCreated()->assertJsonPath('name', 'rent - KANDY office');
        $this->accKdy()->postJson("/api/v1/expense-templates/{$first->id}/restore")->assertUnprocessable()
            ->assertJsonPath('message', 'An active template is already named Rent - Kandy office.');
        $this->assertNotNull($first->fresh()->archived_at);
        // R3: 3-100 characters after trimming.
        $this->createTpl($this->rentBody(['name' => '  ab  ']))->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->createTpl($this->rentBody(['name' => str_repeat('x', 101)]))->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_ac5_account_and_category_rules(): void
    {
        $account = 'Choose an active cash, bank or wallet account of this branch.';
        $category = 'Choose an active expense sub-category of this branch.';
        foreach ([$this->kdyOldCash->id, $this->galCash->id] as $id) {
            $this->createTpl($this->rentBody(['account_id' => $id]))->assertUnprocessable()->assertJsonPath('errors.account_id.0', $account);
        }
        $this->createTpl($this->rentBody(['account_id' => $this->ho($this->kdy)->id]))->assertUnprocessable()->assertJsonPath('errors.account_id.0', $account);
        $income = Category::where('type', 'income')->whereNotNull('parent_id')->firstOrFail();
        foreach ([$this->rentParent->id, $income->id, $this->godown->id, 999999] as $id) {
            $this->createTpl($this->rentBody(['category_id' => $id]))->assertUnprocessable()->assertJsonPath('errors.category_id.0', $category);
        }
        $this->createTpl($this->rentBody(['category_id' => $this->storeRoom->id]))->assertCreated();
        $this->assertSame(1, RecurringTemplate::withoutGlobalScopes()->count());
    }

    public function test_ac6_amount_rules(): void
    {
        foreach (['0', '-1', '1.234', 'abc', '10000000000000.00', 0, -5, 1.234, ['1']] as $i => $amount) {
            $this->createTpl($this->rentBody(['name' => "T{$i} amount", 'amount' => $amount]))->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
        $empty = $this->createTpl($this->rentBody(['name' => 'Empty amount', 'amount' => '']))->assertCreated()->assertJsonPath('amount', null);
        $this->assertNull(RecurringTemplate::withoutGlobalScopes()->find($empty->json('id'))->amount_cents);
        $this->createTpl($this->rentBody(['name' => 'Null amount', 'amount' => null]))->assertCreated()->assertJsonPath('amount', null);
        $this->createTpl($this->rentBody(['name' => 'One cent', 'amount' => '0.01']))->assertCreated()->assertJsonPath('amount', '0.01');
        $this->createTpl($this->rentBody(['name' => 'Max', 'amount' => '9999999999999.99']))->assertCreated()->assertJsonPath('amount', '9999999999999.99');
        // Other fields: payment method list, frequency list, lengths.
        $this->createTpl($this->rentBody(['name' => 'Bad method', 'payment_method' => 'crypto']))->assertJsonValidationErrors('payment_method');
        $this->createTpl($this->rentBody(['name' => 'Bad frequency', 'frequency' => 'weekly']))->assertJsonValidationErrors('frequency');
        $this->createTpl($this->rentBody(['name' => 'Long payee', 'payee_payer' => str_repeat('p', 256)]))->assertJsonValidationErrors('payee_payer');
        $this->createTpl($this->rentBody(['name' => 'Long text', 'description' => str_repeat('d', 2001)]))->assertJsonValidationErrors('description');
    }

    public function test_ac7_another_branch_gets_404_and_cannot_use_the_template(): void
    {
        $rent = $this->tRent();
        $gal = $this->as($this->galAcc, $this->gal);
        $gal->getJson("/api/v1/expense-templates/{$rent->id}")->assertNotFound();
        $gal->putJson("/api/v1/expense-templates/{$rent->id}", $this->rentBody(['account_id' => $this->galCash->id]))->assertNotFound();
        $gal->postJson("/api/v1/expense-templates/{$rent->id}/archive", [])->assertNotFound();
        $gal->postJson("/api/v1/expense-templates/{$rent->id}/restore", [])->assertNotFound();
        $count = Transaction::withoutGlobalScopes()->count();
        $this->useTpl($rent, ['account_id' => $this->galCash->id, 'amount' => '100.00'], $this->galAcc, $this->gal)->assertUnprocessable()
            ->assertJsonPath('message', 'Template not found in this branch.');
        $this->assertSame($count, Transaction::withoutGlobalScopes()->count());
        $this->assertNull($rent->fresh()->archived_at);
    }

    public function test_ac8_super_admin_reads_all_branches_and_changes_only_in_branch_mode(): void
    {
        $rent = $this->tRent();
        $gal = $this->createTpl($this->rentBody(['account_id' => $this->galCash->id]), $this->galAcc, $this->gal)->assertCreated()->json('id');
        $all = $this->list($this->admin, 'all');
        $this->assertNull($all['threshold']);
        $this->assertEqualsCanonicalizing(['KDY', 'GAL'], array_column(array_column($all['data'], 'branch'), 'code'));
        $this->assertFalse($all['data'][0]['can_edit']);
        $this->assertTrue($all['data'][0]['can_use']);
        $this->sa()->postJson('/api/v1/expense-templates', $this->rentBody(['name' => 'SA']))->assertUnprocessable()->assertJsonPath('message', 'Choose a branch first.');
        $this->sa()->putJson("/api/v1/expense-templates/{$rent->id}", $this->rentBody())->assertUnprocessable()->assertJsonPath('message', 'Choose a branch first.');
        $this->sa()->postJson("/api/v1/expense-templates/{$gal}/archive", [])->assertUnprocessable()->assertJsonPath('message', 'Choose a branch first.');

        $kdy = $this->sa($this->kdy);
        $id = $kdy->postJson('/api/v1/expense-templates', $this->rentBody(['name' => 'SA rent']))->assertCreated()->json('id');
        $kdy->putJson("/api/v1/expense-templates/{$id}", $this->rentBody(['name' => 'SA rent', 'amount' => '1.00']))->assertOk()->assertJsonPath('amount', '1.00');
        $kdy->postJson("/api/v1/expense-templates/{$id}/archive", ['reason' => 'Test'])->assertOk();
        $kdy->postJson("/api/v1/expense-templates/{$id}/restore")->assertOk()->assertJsonPath('archived_at', null);
        $this->assertSame(['KDY'], array_unique(array_column(array_column($this->list($this->admin, $this->kdy)['data'], 'branch'), 'code')));
    }

    public function test_ac9_update_audits_changed_fields_and_version_only(): void
    {
        $rent = $this->tRent();
        $this->accKdy()->putJson("/api/v1/expense-templates/{$rent->id}", $this->rentBody(['amount' => '155000.00']))->assertOk()
            ->assertJsonPath('amount', '155000.00')->assertJsonPath('version', 2);
        $rows = $this->templateAudits($rent, 'expense_template.updated');
        $this->assertCount(1, $rows);
        $this->assertSame(['amount' => '150000.00', 'version' => 1], self::sorted($rows[0]->old_values));
        $this->assertSame(['amount' => '155000.00', 'version' => 2], self::sorted($rows[0]->new_values));
        $this->assertSame($this->kdy->id, (int) $rows[0]->branch_id);
        // No change: no row and no new version (name whitespace normalised the same way).
        $this->accKdy()->putJson("/api/v1/expense-templates/{$rent->id}", $this->rentBody(['amount' => '155000', 'name' => ' Rent -  Kandy office']))
            ->assertOk()->assertJsonPath('version', 2);
        $this->assertCount(1, $this->templateAudits($rent, 'expense_template.updated'));
        $this->assertSame($this->acc->id, (int) $rent->fresh()->updated_by);
    }

    public function test_ac20_21_archive_and_restore(): void
    {
        $rent = $this->tRent();
        $kdy = $this->accKdy();
        $kdy->postJson("/api/v1/expense-templates/{$rent->id}/archive", ['reason' => str_repeat('r', 501)])->assertJsonValidationErrors('reason');
        $kdy->postJson("/api/v1/expense-templates/{$rent->id}/restore")->assertUnprocessable()->assertJsonPath('message', 'This template is not archived.');
        $kdy->postJson("/api/v1/expense-templates/{$rent->id}/archive", ['reason' => 'Lease ended'])->assertOk()
            ->assertJsonPath('archived_by.name', $this->acc->name)->assertJsonPath('archive_reason', 'Lease ended')->assertJsonPath('can_use', false);
        $kdy->postJson("/api/v1/expense-templates/{$rent->id}/archive", [])->assertUnprocessable()->assertJsonPath('message', 'This template is already archived.');
        $kdy->putJson("/api/v1/expense-templates/{$rent->id}", $this->rentBody(['amount' => '1.00']))->assertUnprocessable()
            ->assertJsonPath('message', 'This template is archived. Restore it first.');

        $this->assertSame([], $this->list($this->csh, $this->kdy)['data']);
        $this->assertSame(1, $this->list($this->csh, $this->kdy)['archived_count']);
        $archived = $this->list($this->acc, $this->kdy, ['archived' => 1]);
        $this->assertSame($rent->id, $archived['data'][0]['id']);
        $this->assertSame('Lease ended', $archived['data'][0]['archive_reason']);
        $this->assertSame($this->acc->id, $archived['data'][0]['archived_by']['id']);
        $this->assertNotNull($archived['data'][0]['archived_at']);
        $rows = $this->templateAudits($rent, 'expense_template.archived');
        $this->assertCount(1, $rows);
        $this->assertSame(['archived_at' => null], $rows[0]->old_values);
        $this->assertSame('Lease ended', $rows[0]->new_values['reason']);

        $kdy->postJson("/api/v1/expense-templates/{$rent->id}/restore")->assertOk()->assertJsonPath('archived_at', null)->assertJsonPath('can_use', true);
        $this->assertCount(1, $this->templateAudits($rent, 'expense_template.restored'));
        $this->assertSame([$rent->id], array_column($this->list($this->csh, $this->kdy)['data'], 'id'));
    }

    public function test_ac22_there_is_no_delete_route(): void
    {
        $rent = $this->tRent();
        $status = $this->sa($this->kdy)->deleteJson("/api/v1/expense-templates/{$rent->id}")->status();
        $this->assertContains($status, [404, 405]);
        $this->assertNotNull(RecurringTemplate::withoutGlobalScopes()->find($rent->id));
    }

    public function test_ac24_put_keeping_an_inactive_category_is_refused_and_s1_flags_it(): void
    {
        $rent = $this->tRent();
        $this->rentParent->update(['is_active' => false]);
        $row = $this->rowOf($this->list($this->acc, $this->kdy), $rent);
        $this->assertFalse($row['category']['active']);
        $this->assertSame('Rent > Office rent', $row['category']['path']);
        $this->accKdy()->putJson("/api/v1/expense-templates/{$rent->id}", $this->rentBody(['amount' => '1.00']))->assertUnprocessable()
            ->assertJsonPath('errors.category_id.0', 'Choose an active expense sub-category of this branch.');
        // Restore re-checks R4 too.
        $this->accKdy()->postJson("/api/v1/expense-templates/{$rent->id}/archive", [])->assertOk();
        $this->accKdy()->postJson("/api/v1/expense-templates/{$rent->id}/restore")->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    public function test_ac25_templates_are_not_affected_by_period_locks(): void
    {
        $this->lock('2026-11', $this->kdy)->assertCreated();
        $rent = $this->tRent();
        $this->accKdy()->putJson("/api/v1/expense-templates/{$rent->id}", $this->rentBody(['amount' => '151000.00']))->assertOk();
        $this->accKdy()->postJson("/api/v1/expense-templates/{$rent->id}/archive", [])->assertOk();
        $this->accKdy()->postJson("/api/v1/expense-templates/{$rent->id}/restore")->assertOk();
    }

    public function test_ac30_audit_log_group_labels_subject_and_branch_isolation(): void
    {
        $rent = $this->tRent();
        $this->accKdy()->putJson("/api/v1/expense-templates/{$rent->id}", $this->rentBody(['payee_payer' => 'New landlord']))->assertOk();
        $this->accKdy()->postJson("/api/v1/expense-templates/{$rent->id}/archive", [])->assertOk();
        $this->accKdy()->postJson("/api/v1/expense-templates/{$rent->id}/restore")->assertOk();
        $this->createTpl($this->rentBody(['name' => 'Galle rent', 'account_id' => $this->galCash->id]), $this->galAcc, $this->gal)->assertCreated();

        $rows = $this->accKdy()->getJson('/api/v1/audit-logs?'.http_build_query(['from' => '2026-11-01', 'to' => '2026-11-30', 'event_group' => 'expense_templates']))
            ->assertOk()->json('data');
        $this->assertSame(['Expense template restored', 'Expense template archived', 'Expense template changed', 'Expense template created'],
            array_column($rows, 'event_label'));
        $this->assertSame(['Rent - Kandy office'], array_values(array_unique(array_column($rows, 'subject'))));
        $this->assertSame(['KDY'], array_values(array_unique(array_column(array_column($rows, 'branch'), 'code'))));
    }

    public function test_r1_template_actions_never_write_transactions(): void
    {
        DB::enableQueryLog();
        $rent = $this->tRent();
        $this->accKdy()->putJson("/api/v1/expense-templates/{$rent->id}", $this->rentBody(['amount' => '1.00']))->assertOk();
        $this->accKdy()->postJson("/api/v1/expense-templates/{$rent->id}/archive", [])->assertOk();
        $this->accKdy()->postJson("/api/v1/expense-templates/{$rent->id}/restore")->assertOk();
        $writes = array_filter(DB::getQueryLog(), fn ($q) => preg_match('/^(insert into|update|delete from)\s+["`]?(transactions|ledger_entries|accounts)["`]?/i', $q['query']));
        $this->assertSame([], array_values($writes));
    }
}
