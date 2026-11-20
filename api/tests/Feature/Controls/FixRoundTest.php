<?php

namespace Tests\Feature\Controls;

use App\Http\Controllers\Api\V1\PeriodLockController;
use App\Models\PeriodLock;
use App\Support\MigrationPreflight;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;

/** Controls fix round: reviewer R2, R3, R5, R6, R7, R10, R11. */
class FixRoundTest extends ControlsTestCase
{
    public function test_r2_preflight_refuses_duplicate_period_locks(): void
    {
        MigrationPreflight::assertNoDuplicatePeriodLocks(); // clean data passes
        foreach ([1, 2] as $i) {
            DB::table('period_locks')->insert(['branch_id' => null, 'period' => '2026-09', 'locked_by' => $this->admin->id,
                'unlocked_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('period_locks has duplicate locks for all branches 2026-09 (2 rows)');
        MigrationPreflight::assertNoDuplicatePeriodLocks();
    }

    public function test_r3_trigger_privilege_decision(): void
    {
        $this->assertNull(MigrationPreflight::triggerPrivilegeProblem(false, false, []));
        $this->assertNull(MigrationPreflight::triggerPrivilegeProblem(true, true, []));
        $this->assertNull(MigrationPreflight::triggerPrivilegeProblem(true, false, ['GRANT ALL PRIVILEGES ON *.* TO `root`@`%` WITH GRANT OPTION']));
        $this->assertNull(MigrationPreflight::triggerPrivilegeProblem(true, false, ['GRANT SELECT, SUPER ON *.* TO `app`@`%`']));
        $this->assertStringContainsString('log_bin_trust_function_creators = 1', (string) MigrationPreflight::triggerPrivilegeProblem(true, false,
            ['GRANT USAGE ON *.* TO `app`@`%`', 'GRANT ALL PRIVILEGES ON `gold`.* TO `app`@`%`']));
        MigrationPreflight::assertCanCreateTriggers(); // no-op on SQLite, passes on the CI MySQL (root)
    }

    public function test_r5_lock_check_is_a_locking_read_inside_postings(): void
    {
        $this->assertSame(false, PeriodLock::lockedQuery($this->kdy->id, '2026-10-05', true)->getQuery()->lock, 'shared lock');
        $this->assertNull(PeriodLock::lockedQuery($this->kdy->id, '2026-10-05')->getQuery()->lock);

        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            if (str_contains($q->sql, 'period_locks')) {
                $sql[] = strtolower($q->sql);
            }
        });
        $this->expense('2026-11-10', '10.00');
        $this->assertNotEmpty($sql);
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->assertTrue(collect($sql)->contains(fn ($s) => str_contains($s, 'lock in share mode') || str_contains($s, 'for share')));
        }
    }

    public function test_r6_deadlock_maps_to_422(): void
    {
        $pdo = new PDOException('Deadlock found when trying to get lock; try restarting transaction');
        $pdo->errorInfo = ['40001', 1213, 'Deadlock found'];
        $e = new QueryException('mysql', 'insert into period_locks ...', [], $pdo);
        $this->assertNotNull(PeriodLockController::concurrencyMessage($e));
        $other = new QueryException('mysql', 'select 1', [], new PDOException('Unknown column'));
        $this->assertNull(PeriodLockController::concurrencyMessage($other));
    }

    public function test_r7_unlock_says_which_months_reopen(): void
    {
        $this->lock('2026-08', $this->kdy)->assertCreated();
        $oct = $this->lock('2026-10', $this->kdy)->assertCreated()->json('id');
        $grid = $this->sa()->getJson('/api/v1/period-locks?year=2026')->json('locks');
        $this->assertSame('Reopens KDY: September 2026 to October 2026.', collect($grid)->firstWhere('id', $oct)['reopens']);

        $res = $this->unlock($oct)->assertOk();
        $this->assertSame([['branch_id' => $this->kdy->id, 'code' => 'KDY', 'from' => '2026-09', 'to' => '2026-10']], $res->json('reopened'));
        $this->assertSame('Reopens KDY: September 2026 to October 2026.', $res->json('message'));
        $this->assertSame('2026-10', $res->json('period'));

        $aug = $this->activeLock('2026-08', $this->kdy);
        $this->assertSame('Reopens KDY: every month up to August 2026 (no earlier lock).', $this->unlock($aug)->assertOk()->json('message'));

        // Global unlock: a branch closed by its own lock of the same month stays closed.
        $this->lock('2026-10', $this->gal)->assertCreated();
        $global = $this->lock('2026-10', null)->assertCreated()->json('id');
        $res = $this->unlock($global)->assertOk();
        $this->assertSame(['MAIN', 'KDY'], array_column($res->json('reopened'), 'code'));
        $this->assertStringContainsString('MAIN: every month up to October 2026', $res->json('message'));
    }

    public function test_r10_today_is_the_colombo_day(): void
    {
        Carbon::setTestNow('2026-11-18 20:00:00'); // 2026-11-19 01:30 in Asia/Colombo
        $this->expense('2026-11-19', '10.00');
        $this->accKdy()->postJson('/api/v1/expenses', ['account_id' => $this->kdyCash->id, 'category_id' => $this->category('expense')->id,
            'txn_date' => '2026-11-20', 'amount' => '10'])->assertStatus(422)->assertJsonValidationErrors('txn_date');
        $this->sa()->postJson('/api/v1/capital', ['account_id' => $this->mainAcc->id, 'amount' => '10', 'txn_date' => '2026-11-19'])->assertCreated();
        $e = $this->expense('2026-11-18', '10.00');
        $this->accKdy()->postJson("/api/v1/transactions/{$e['id']}/reverse", ['reason' => 'Wrong'])->assertCreated()->assertJsonPath('txn_date', '2026-11-19');

        Carbon::setTestNow('2026-11-30 19:00:00'); // 2026-12-01 00:30 in Asia/Colombo
        $this->lock('2026-12', $this->kdy)->assertCreated();
        $this->lock('2027-01', $this->kdy)->assertStatus(422)->assertJsonPath('message', 'Future months cannot be locked.');
    }

    public function test_r11_lock_status_for_branch_users(): void
    {
        $this->accKdy()->getJson('/api/v1/period-locks/status')->assertOk()->assertJsonPath('closed_through', null);
        $this->lock('2026-10', $this->kdy)->assertCreated();
        $this->accKdy()->getJson('/api/v1/period-locks/status')->assertOk()
            ->assertJsonPath('closed_through', '2026-10')->assertJsonPath('label', 'October 2026');
        $this->as($this->ba, $this->gal)->getJson('/api/v1/period-locks/status')->assertOk()->assertJsonPath('closed_through', null);
        $this->lock('2026-11', null)->assertCreated();
        $this->as($this->ba, $this->gal)->getJson('/api/v1/period-locks/status')->assertOk()->assertJsonPath('closed_through', '2026-11');
        $this->as($this->csh, $this->kdy)->getJson('/api/v1/period-locks/status')->assertOk()->assertJsonPath('closed_through', '2026-11');
        $this->sa()->getJson('/api/v1/period-locks/status')->assertOk()->assertJsonPath('closed_through', null);
    }
}
