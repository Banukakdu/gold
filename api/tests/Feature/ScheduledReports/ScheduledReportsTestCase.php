<?php

namespace Tests\Feature\ScheduledReports;

use App\Jobs\RunScheduledReport;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Export;
use App\Models\ReportSchedule;
use App\Models\ReportScheduleRun;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\ReportScheduleProblemMail;
use App\Notifications\ScheduledReportMail;
use App\Services\LedgerService;
use App\Support\ReadSnapshot;
use Carbon\CarbonImmutable;
use Database\Seeders\ReportExampleSeeder;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Tests\Feature\AccountingTestCase;
use Throwable;

/**
 * Fixture of docs/specs/scheduled-reports.md section 7: the clock at Fri 2026-11-20 10:00 Asia/Colombo; branches MAIN,
 * KDY, GAL with the reports E-dataset plus KDY entries on 2026-11-20 (two incomes, one expense) and a KDY top-up on
 * 2026-11-17; Super Admins SA (`admin`) and SA2; KDY Branch Admin KBA (also a GAL Viewer); KDY Accountant AK; KDY
 * Viewer KV; GAL Accountant GA; inactive KDY Accountant AX.
 *
 * RunScheduledReport and every queued notification are faked on the queue: work() runs a run as the exports worker
 * would (with its retry), sendMails() runs the queued scheduled report emails (the normal worker), and the array
 * mailer keeps what was sent.
 */
abstract class ScheduledReportsTestCase extends AccountingTestCase
{
    protected Branch $kdy;

    protected Branch $gal;

    protected User $sa2;

    protected User $kba;

    protected User $ak;

    protected User $kv;

    protected User $ga;

    protected User $ax;

    /** Index of the next queued notification job sendMails() has not run yet. */
    private int $mailCursor = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'Asia/Colombo'));
        $this->seed(ReportExampleSeeder::class);
        Storage::fake(Export::DISK);
        Queue::fake([RunScheduledReport::class, SendQueuedNotifications::class]);
        config(['app.frontend_url' => 'https://gold.example']);

        $this->admin->update(['name' => 'Sunil Admin', 'email' => 'sa@gold.example']);
        $this->kdy = Branch::where('code', 'KDY')->first();
        $this->gal = Branch::where('code', 'GAL')->first();

        $this->sa2 = User::factory()->create(['name' => 'Second Admin', 'email' => 'sa2@gold.example', 'status' => 'active']);
        $this->sa2->forceFill(['is_super_admin' => true])->save();
        $this->kba = $this->userIn($this->kdy, 'Branch Admin');
        $this->kba->update(['name' => 'Kamal Branch Admin', 'email' => 'kba@gold.example']);
        $this->kba->branches()->attach($this->gal->id);
        setPermissionsTeamId($this->gal->id);
        $this->kba->assignRole($this->branchRole($this->gal, 'Viewer'));
        $this->ak = $this->userIn($this->kdy, 'Accountant');
        $this->ak->update(['name' => 'Anula Kumari', 'email' => 'ak@gold.example']);
        $this->kv = $this->userIn($this->kdy, 'Viewer');
        $this->kv->update(['name' => 'Kasun Viewer', 'email' => 'kv@gold.example']);
        $this->ga = $this->userIn($this->gal, 'Accountant');
        $this->ga->update(['name' => 'Gamini Accountant', 'email' => 'ga@gold.example']);
        $this->ax = $this->userIn($this->kdy, 'Accountant');
        $this->ax->update(['name' => 'Ajith Inactive', 'email' => 'ax@gold.example', 'status' => 'inactive']);
        setPermissionsTeamId(null);

        $ledger = app(LedgerService::class);
        $kdyCash = $this->cashOf($this->kdy);
        Auth::setUser($this->admin);
        $ledger->transfer($this->mainAcc->id, $kdyCash->id, '5000.00', '2026-11-17', 'Top-up for the week');
        Auth::setUser($this->ak);
        $retail = Category::where('name', 'Retail')->first();
        $rent = Category::where('name', 'Rent')->first();
        $ledger->incomeOrExpense(Transaction::INCOME, ['account_id' => $kdyCash->id, 'category_id' => $retail->id, 'txn_date' => '2026-11-20', 'amount' => '1500.00']);
        $ledger->incomeOrExpense(Transaction::INCOME, ['account_id' => $kdyCash->id, 'category_id' => $retail->id, 'txn_date' => '2026-11-20', 'amount' => '2250.00']);
        $ledger->incomeOrExpense(Transaction::EXPENSE, ['account_id' => $kdyCash->id, 'category_id' => $rent->id, 'txn_date' => '2026-11-20', 'amount' => '400.00', 'payee_payer' => 'Landlord']);
        Auth::forgetUser();
    }

    protected function tearDown(): void
    {
        ReadSnapshot::$hook = null;
        parent::tearDown();
    }

    // ------------------------------------------------------------------ requests

    protected function as(User $u)
    {
        return $this->actingAs($u)->withHeader('X-Branch-Id', $u->is_super_admin ? 'all' : (string) $this->kdy->id)
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->withHeader('User-Agent', 'ScheduleTest/1.0');
    }

    /** D1: "KDY daily I&E", Income & Expense, Type All, [KDY], daily 07:00, SA + AK, owner SA. */
    protected function d1Body(array $over = []): array
    {
        return array_replace(['name' => 'KDY daily I&E', 'report' => 'income-expense', 'params' => ['type' => 'all'], 'branches' => [$this->kdy->id],
            'frequency' => 'daily', 'weekday' => null, 'send_time' => '07:00', 'recipient_ids' => [$this->admin->id, $this->ak->id]], $over);
    }

    /** W1: "Weekly comparison", Branch Comparison, All branches, weekly Monday 07:00, SA + SA2. */
    protected function w1Body(array $over = []): array
    {
        return array_replace(['name' => 'Weekly comparison', 'report' => 'branch-comparison', 'params' => [], 'branches' => [],
            'frequency' => 'weekly', 'weekday' => 1, 'send_time' => '07:00', 'recipient_ids' => [$this->admin->id, $this->sa2->id]], $over);
    }

    protected function createD1(array $over = []): ReportSchedule
    {
        $id = $this->as($this->admin)->postJson('/api/v1/report-schedules', $this->d1Body($over))->assertCreated()->json('id');

        return ReportSchedule::findOrFail($id);
    }

    protected function createW1(array $over = []): ReportSchedule
    {
        $id = $this->as($this->admin)->postJson('/api/v1/report-schedules', $this->w1Body($over))->assertCreated()->json('id');

        return ReportSchedule::findOrFail($id);
    }

    /** Move the clock (Asia/Colombo) and run the dispatcher. */
    protected function dispatchAt(string $local): void
    {
        $this->travelTo(CarbonImmutable::parse($local, 'Asia/Colombo'));
        $this->artisan('reports:dispatch-scheduled')->assertExitCode(0);
    }

    // ------------------------------------------------------------------ the workers

    /**
     * Run RunScheduledReport as the `exports` worker would: up to $tries attempts; an exception retries, the last one
     * calls failed(). $this->fail() inside the job ends it (no retry).
     */
    protected function work(?string $id = null, int $tries = 2): RunScheduledReport
    {
        $id ??= ReportScheduleRun::where('status', ReportScheduleRun::QUEUED)->orderByDesc('planned_at')->value('id');
        for ($attempt = 1; ; $attempt++) {
            $job = (new RunScheduledReport($id))->withFakeQueueInteractions();
            $job->job->attempts = $attempt;
            try {
                app()->call([$job, 'handle']);

                return $job;
            } catch (Throwable $e) {
                if ($attempt >= $tries) {
                    $job->failed($e);

                    return $job;
                }
            }
        }
    }

    /** The queued notification jobs of $class pushed so far. @return SendQueuedNotifications[] */
    protected function queuedNotifications(string $class): array
    {
        return array_values(array_filter(Queue::pushed(SendQueuedNotifications::class)->all(), fn ($j) => $j->notification instanceof $class));
    }

    /**
     * Run the scheduled report emails queued since the last call, as the normal worker would ($tries attempts, then
     * failed()).
     */
    protected function sendMails(): void
    {
        $jobs = array_slice(Queue::pushed(SendQueuedNotifications::class)->all(), $this->mailCursor);
        $this->mailCursor += count($jobs);
        foreach ($jobs as $job) {
            if (! $job->notification instanceof ScheduledReportMail) {
                continue;
            }
            $tries = $job->notification->tries;
            for ($attempt = 1; ; $attempt++) {
                try {
                    $job->handle(app(ChannelManager::class));
                    break;
                } catch (Throwable $e) {
                    if ($attempt >= $tries) {
                        $job->failed($e);
                        break;
                    }
                }
            }
        }
    }

    /** Emails the array mailer accepted. @return Email[] */
    protected function sentEmails(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage())->values()->all();
    }

    /** @return Email[] */
    protected function emailsTo(string $address): array
    {
        return array_values(array_filter($this->sentEmails(), fn (Email $e) => collect($e->getTo())->contains(fn ($a) => $a->getAddress() === $address)));
    }

    protected static function text(Email $e): string
    {
        return html_entity_decode(strip_tags((string) $e->getHtmlBody()), ENT_QUOTES).' '.(string) $e->getTextBody();
    }

    /** ReportScheduleProblemMail jobs queued to $user. */
    protected function problemMailsTo(User $user): array
    {
        return array_values(array_filter($this->queuedNotifications(ReportScheduleProblemMail::class),
            fn ($j) => collect($j->notifiables)->contains(fn ($n) => $n instanceof User && $n->id === $user->id)));
    }

    protected function bell(User $u, ?string $kind = null)
    {
        return DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $u->id)
            ->when($kind, fn ($q) => $q->where('type', $kind))->get();
    }

    protected function latestRun(ReportSchedule $s): ReportScheduleRun
    {
        return ReportScheduleRun::where('schedule_id', $s->id)->orderByDesc('planned_at')->firstOrFail();
    }

    /** The books: every transaction, ledger line and account balance (AC 33). */
    protected function books(): array
    {
        return [
            DB::table('transactions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('ledger_entries')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(),
            Account::withoutGlobalScopes()->orderBy('id')->pluck('current_balance', 'id')->all(),
        ];
    }

    /** Text of each PDF page (as ReportTestCase::pdfPages). @return string[] */
    protected function pdfPages(string $pdf): array
    {
        $this->assertStringStartsWith('%PDF', $pdf);
        $pages = [];
        preg_match_all('/\/Length (\d+)[^>]*>>\s*stream\r?\n/', $pdf, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($m as $hit) {
            $raw = substr($pdf, $hit[0][1] + strlen($hit[0][0]), (int) $hit[1][0]);
            $content = @gzuncompress($raw);
            if ($content === false || str_contains($content, "\0") || ! str_contains($content, ' Tf')) {
                continue;
            }
            preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)/s', $content, $s);
            $pages[] = implode(' ', array_map(fn ($x) => stripcslashes(substr($x, 1, -1)), $s[0]));
        }

        return $pages;
    }

    protected function storedPdf(ReportScheduleRun $run): string
    {
        return Storage::disk(Export::DISK)->get($run->file_path);
    }
}
