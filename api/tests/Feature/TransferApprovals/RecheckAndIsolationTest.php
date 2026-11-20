<?php

namespace Tests\Feature\TransferApprovals;

use App\Exceptions\LedgerException;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\YearEndClose;
use App\Notifications\TransferApprovalDecidedNotification;
use App\Services\LedgerService;
use App\Services\TransferApprovals;
use App\Support\Settings;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Mockery;
use RuntimeException;

/**
 * Review M2 and minors: re-checks at approval (closed year, inactive account or branch), branch isolation, R4 for a
 * Super Admin, the conditional update, mail failures, the email's comment and a malformed stored threshold.
 * Real concurrency is covered by the reviewer's MySQL fork probe only: the test database transaction is invisible to
 * a second connection, so two simultaneous requests cannot be run inside a feature test.
 */
class RecheckAndIsolationTest extends TransferApprovalTestCase
{
    public function test_a_closed_year_at_approval_refuses_and_the_request_stays_pending(): void
    {
        $t = $this->t1();
        YearEndClose::create(['fiscal_year' => 2026, 'period_start' => '2026-04-01', 'period_end' => '2027-03-31', 'status' => YearEndClose::CLOSED,
            'closed_by' => $this->admin->id, 'closed_at' => now(), 'summary' => ['by_branch' => [], 'total_net' => '0.00']]);
        $before = $this->state();
        $r = $this->approve($t->id)->assertStatus(422);
        $this->assertStringEndsWith('is closed.', $r->json('message'));
        $this->assertSame($before, $this->state());
        $this->assertSame('pending', $t->fresh()->approval_status);
    }

    public function test_an_account_or_branch_deactivated_while_pending_refuses_approval(): void
    {
        $t = $this->t1();
        DB::table('accounts')->where('id', $this->galCash->id)->update(['status' => 'inactive']);
        $before = $this->state();
        $this->approve($t->id)->assertStatus(422)->assertJsonPath('message', 'Account GAL Cash or its branch is inactive.');
        $this->assertSame($before, $this->state());

        DB::table('accounts')->where('id', $this->galCash->id)->update(['status' => 'active']);
        DB::table('branches')->where('id', $this->kdy->id)->update(['status' => 'inactive']);
        $this->approve($t->id)->assertStatus(422)->assertJsonPath('message', 'Account KDY Cash or its branch is inactive.');
        $this->assertSame(0, LedgerEntry::where('transaction_id', $t->id)->count());
        // R15: reject it (or let it expire).
        $this->reject($t->id)->assertOk()->assertJsonPath('approval_status', 'rejected');
    }

    public function test_a_third_branch_sees_nothing_and_the_receiving_branch_cannot_approve(): void
    {
        $t = $this->t1();
        $mtr = $this->makeBranch('MTR');
        $mtrBa = $this->userIn($mtr, 'Branch Admin');
        $before = $this->state();
        $this->approve($t->id, $mtrBa, $mtr)->assertNotFound();
        $this->reject($t->id, by: $mtrBa, branch: $mtr)->assertNotFound();
        $this->cancel($t->id, $mtrBa, $mtr)->assertNotFound();
        $this->as($mtrBa, $mtr)->getJson("/api/v1/transactions/{$t->id}")->assertNotFound();
        $this->assertSame([], $this->as($mtrBa, $mtr)->getJson('/api/v1/transactions?status=pending')->assertOk()->json('data'));
        $this->assertSame([], $this->widget($mtrBa, $mtr)['to_approve']['data']);

        // GAL (receiving) Branch Admin holds transfer.approve in GAL: still not Main.
        $this->approve($t->id, $this->ba, $this->gal)->assertForbidden()->assertJsonPath('message', 'Transfers are approved by Main.');
        $this->assertSame($before, $this->state());
    }

    public function test_r4_a_super_admin_who_made_the_request_cannot_approve_it(): void
    {
        $t = $this->t1();
        $this->acc->forceFill(['is_super_admin' => true])->save();
        $before = $this->state();
        $msg = 'You requested this transfer. Someone else in Main must approve it.';
        $this->approve($t->id, $this->acc->fresh(), 'all')->assertForbidden()->assertJsonPath('message', $msg);
        $this->reject($t->id, by: $this->acc->fresh(), branch: 'all')->assertForbidden()->assertJsonPath('message', $msg);
        $this->assertSame($before, $this->state());
        $this->approve($t->id, $this->admin, 'all')->assertOk();
    }

    public function test_approver_branch_records_the_branch_the_approver_worked_in(): void
    {
        $t = $this->t1();
        $this->approve($t->id, $this->admin, $this->kdy)->assertOk();
        $this->assertSame('KDY', $this->audits($t, 'transfer.approved')[0]->new_values['approver_branch']);
    }

    public function test_post_approved_refuses_a_row_no_longer_pending_and_posts_nothing(): void
    {
        $t = $this->t1();
        DB::table('transactions')->where('id', $t->id)->update(['status' => 'rejected', 'approval_status' => 'rejected']);
        Auth::setUser($this->mainBa);
        try {
            app(LedgerService::class)->postApproved($t);
            $this->fail('postApproved posted a rejected request');
        } catch (LedgerException $e) {
            $this->assertSame(TransferApprovals::CHANGED, $e->getMessage());
        }
        $this->assertSame(0, LedgerEntry::where('transaction_id', $t->id)->count());
        $this->assertSame('200000.00', $this->bal($this->kdyCash));
    }

    public function test_a_mail_failure_does_not_turn_a_committed_approval_into_an_error(): void
    {
        $t = $this->t1();
        $mail = Mockery::mock(Dispatcher::class);
        $mail->shouldReceive('send')->andThrow(new RuntimeException('SMTP down'));
        Notification::swap($mail);
        $this->approve($t->id)->assertOk()->assertJsonPath('status', 'posted');
        $this->assertSame('approved', $t->fresh()->approval_status);
    }

    public function test_the_comment_is_shown_as_text_in_the_email(): void
    {
        $t = $this->t1();
        $this->reject($t->id, 'See [Open](https://evil.example) *now* <b>x</b>')->assertOk();
        $mail = (new TransferApprovalDecidedNotification($t->id, 'rejected'))->toMail($this->acc);
        $line = collect($mail->introLines)->first(fn ($l) => str_starts_with((string) $l, 'Comment: '));
        $this->assertStringNotContainsString('[Open](', $line);
        $this->assertStringNotContainsString('<b>', $line);
        $this->assertStringContainsString('\[Open\]\(https://evil\.example\)', $line);
    }

    public function test_a_malformed_stored_threshold_is_logged_and_shown(): void
    {
        DB::table('settings')->where('key', 'transfer_approval_threshold')->update(['value' => '100000.00']);
        app(Settings::class)->flush();
        Log::spy();
        $this->request('150000.00')->assertCreated()->assertJsonPath('status', 'posted');
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'transfer_approval_threshold'))->atLeast()->once();
        $this->sa()->getJson('/api/v1/settings')->assertOk()->assertJsonPath('transfer_approval_threshold', null)
            ->assertJsonPath('transfer_approval_warning', 'The stored approval threshold is not valid, so no transfer is held. Enter it again and save.');
        $this->assertSame(0, Transaction::withoutGlobalScopes()->whereNotNull('approval_status')->count());
    }

    public function test_the_detail_says_when_the_requester_is_inactive(): void
    {
        $t = $this->t1();
        $this->as($this->mainBa, $this->main)->getJson("/api/v1/transactions/{$t->id}")->assertOk()->assertJsonPath('requester_inactive', false);
        $this->acc->forceFill(['status' => 'inactive'])->save();
        $this->as($this->mainBa, $this->main)->getJson("/api/v1/transactions/{$t->id}")->assertOk()->assertJsonPath('requester_inactive', true)
            ->assertJsonPath('can_approve', true);
    }
}
