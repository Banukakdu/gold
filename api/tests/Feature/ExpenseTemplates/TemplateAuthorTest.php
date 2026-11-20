<?php

namespace Tests\Feature\ExpenseTemplates;

use App\Models\AuditLog;
use App\Models\RecurringTemplate;
use App\Models\Transaction;
use App\Notifications\InAppNotification as N;
use App\Services\ExpenseApprovals;
use Illuminate\Notifications\DatabaseNotification;

/**
 * AG-066 R7b / AC 28i (accountant sign-off finding A): whoever set up or changed a template since its last approved
 * use may not approve or reject a use of it; a Super Admin may.
 */
class TemplateAuthorTest extends ExpenseTemplateTestCase
{
    private function requestAudit(int $id): array
    {
        return AuditLog::where('event', 'expense.approval_requested')->where('auditable_type', Transaction::class)->where('auditable_id', $id)->sole()->new_values;
    }

    private function widgetRow(int $id, $by): array
    {
        return collect($this->widget($by, $this->kdy)['to_approve']['data'])->firstWhere('id', $id);
    }

    private function notified(int $id): array
    {
        return DatabaseNotification::where('type', N::EXPENSE_APPROVAL_REQUESTED)->get()->filter(fn ($n) => (int) ($n->data['transaction_id'] ?? 0) === $id)
            ->pluck('notifiable_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
    }

    public function test_ac28i_a_branch_admin_who_changed_the_template_cannot_decide_and_the_super_admin_can(): void
    {
        $rent = $this->tRent();
        $first = $this->useTpl($rent)->assertCreated()->json('id');
        // First use: the template's author is the KDY Accountant, so the KDY Branch Admin may approve.
        $this->assertSame([$this->acc->id], $this->requestAudit($first)['template_authors']);
        $this->approve($first)->assertOk();

        $this->as($this->kdyBa, $this->kdy)->putJson("/api/v1/expense-templates/{$rent->id}", $this->rentBody(['payee_payer' => 'K. Perera']))
            ->assertOk()->assertJsonPath('version', 2);
        $this->at('2026-12-01');
        $held = $this->useTpl($rent)->assertCreated()->assertJsonPath('status', 'pending')->json('id');
        $a = $this->requestAudit($held);
        $this->assertSame([$this->kdyBa->id], $a['template_authors']);
        $this->assertSame([$this->kdyBa->name], $a['template_author_names']);

        $this->approve($held)->assertForbidden()->assertJsonPath('message', ExpenseApprovals::TEMPLATE_AUTHOR);
        $this->reject($held)->assertForbidden()->assertJsonPath('message', ExpenseApprovals::TEMPLATE_AUTHOR);
        $this->assertSame('pending', $this->txn($held)->approval_status);

        // The widget: the row with the tag flag and no decision for the author; the other Branch Admin decides.
        $row = $this->widgetRow($held, $this->kdyBa);
        $this->assertTrue($row['template_author']);
        $this->assertFalse($row['can_approve']);
        $this->assertSame([$this->kdyBa->id], $row['template_authors']);
        $other = $this->widgetRow($held, $this->kdyBa2);
        $this->assertFalse($other['template_author']);
        $this->assertTrue($other['can_approve']);
        $this->as($this->kdyBa, $this->kdy)->getJson("/api/v1/transactions/{$held}")->assertOk()->assertJsonPath('can_approve', false);
        // No request notification (or email) to the author.
        $this->assertSame(collect([$this->kdyBa2->id, $this->admin->id])->sort()->values()->all(), $this->notified($held));

        // A Super Admin may still decide (as R4b).
        $this->assertFalse($this->widgetRow($held, $this->admin)['template_author']);
        $this->approve($held, $this->admin, 'all')->assertOk()->assertJsonPath('status', 'posted');
    }

    public function test_ac28i_the_first_use_of_a_template_the_branch_admin_created_is_refused_to_them(): void
    {
        $id = $this->createTpl($this->rentBody(), $this->kdyBa)->assertCreated()->json('id');
        $tpl = RecurringTemplate::withoutGlobalScopes()->findOrFail($id);
        $held = $this->useTpl($tpl)->assertCreated()->assertJsonPath('status', 'pending')->json('id');
        $a = $this->requestAudit($held);
        $this->assertTrue($a['template_first_review']);
        $this->assertSame([$this->kdyBa->id], $a['template_authors']);
        $this->approve($held)->assertForbidden()->assertJsonPath('message', ExpenseApprovals::TEMPLATE_AUTHOR);
        $this->approve($held, $this->kdyBa2)->assertOk();
    }

    public function test_ac28i_a_change_made_before_the_last_approved_use_does_not_count(): void
    {
        $rent = $this->tRent();
        $this->as($this->kdyBa, $this->kdy)->putJson("/api/v1/expense-templates/{$rent->id}", $this->rentBody(['payee_payer' => 'K. Perera']))->assertOk();
        $first = $this->useTpl($rent)->assertCreated()->json('id');
        $this->assertSame([$this->acc->id, $this->kdyBa->id], $this->requestAudit($first)['template_authors']);
        $this->approve($first, $this->kdyBa2)->assertOk();

        $this->at('2026-12-01');
        $held = $this->useTpl($rent)->assertCreated()->assertJsonPath('status', 'pending')->json('id');
        $a = $this->requestAudit($held);
        $this->assertSame(2, $a['template_reviewed_version']);
        $this->assertSame([], $a['template_authors']);
        $this->assertTrue($this->widgetRow($held, $this->kdyBa)['can_approve']);
        $this->approve($held)->assertOk();
    }
}
