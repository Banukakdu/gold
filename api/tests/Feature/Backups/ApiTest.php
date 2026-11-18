<?php

namespace Tests\Feature\Backups;

use App\Models\Backup;
use Illuminate\Support\Carbon;

/** Section 7 "Screen and API" (AC 34, 35, 36, 37, 40; the screen parts of 36-39 are in the web build). */
class ApiTest extends BackupsTestCase
{
    private function make(string $colombo, string $status = 'succeeded', array $extra = []): Backup
    {
        $t = Carbon::parse($colombo, 'Asia/Colombo');

        return Backup::create($extra + ['filename' => 'ag-backup-'.$t->format('Ymd-His').'.zip', 'status' => $status, 'triggered_by' => 'scheduler',
            'started_at' => $t->copy()->utc(), 'finished_at' => $t->copy()->utc()->addSeconds(75), 'db_driver' => 'mysql', 'encrypted' => true,
            'kept_as' => 'daily', 'size_bytes' => 3_435_973_837, 'sha256' => str_repeat('ab', 32), 'files_count' => 1204, 'offsite_status' => 'copied']);
    }

    public function test_ac34_super_admin_gets_rows_newest_first_without_paths(): void
    {
        config(['backup.offsite_disk' => 'backups_offsite']);
        $this->make('2026-11-16 01:30:00');
        $this->make('2026-11-17 01:30:00', 'failed', ['error' => 'mysqldump failed: cannot write /var/www/api/storage/app/backup-temp/x/db.sql.raw on backups_offsite',
            'offsite_error' => 'Error executing PutObject on https://bucket.s3.amazonaws.com/daily/x.zip', 'failed_stage' => 'dump']);
        $this->make('2026-11-18 01:30:00');
        foreach (['all', (string) $this->main->id, null] as $branch) {
            $req = $this->flushHeaders()->actingAs($this->sa);
            $res = ($branch === null ? $req : $req->withHeader('X-Branch-Id', $branch))->getJson('/api/v1/backups')->assertOk();
            $this->assertSame(['ag-backup-20261118-013000.zip', 'ag-backup-20261117-013000.zip', 'ag-backup-20261116-013000.zip'],
                array_column($res->json('data'), 'filename'));
        }
        $json = json_encode($res->json());
        $this->assertStringNotContainsString('/var/www', $json);
        $this->assertStringNotContainsString('backup-temp', $json);
        $this->assertStringNotContainsString('backups_offsite', $json);
        $this->assertDoesNotMatchRegularExpression('#https?:#', $json);
        $this->assertStringNotContainsString(storage_path(), $json);
        $failed = $res->json('data.1');
        $this->assertSame('dump', $failed['failed_stage']);
        $this->assertStringContainsString('mysqldump failed', $failed['error']);
        $this->assertSame(['daily' => 30, 'min_keep' => 7, 'monthly' => 12, 'year_end_years' => 8], self::ksorted($res->json('summary.retention')));
        $this->assertSame(75, $res->json('data.0.duration_seconds'));
        $this->assertSame(['backup' => '01:30', 'timezone' => 'Asia/Colombo', 'verify' => 'Sun 03:00', 'verify_oldest' => '1st Sun 04:00'],
            self::ksorted($res->json('summary.schedule')));
        $this->assertTrue($res->json('summary.offsite_configured'));
        $this->assertSame('ag-backup-20261118-013000.zip', $res->json('summary.latest.filename'));
        $this->assertNotNull($res->json('summary.last_success_at'));
    }

    public function test_ac35_branch_users_get_403(): void
    {
        foreach ([$this->ba, $this->acc] as $u) {
            foreach ([(string) $this->kdy->id, 'all', null] as $branch) {
                $req = $this->flushHeaders()->actingAs($u);
                ($branch === null ? $req : $req->withHeader('X-Branch-Id', $branch))->getJson('/api/v1/backups')
                    ->assertForbidden()->assertJson(['message' => 'Super Admin only.']);
            }
        }
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/backups')->assertUnauthorized();
    }

    public function test_deactivated_super_admin_cannot_open_it(): void
    {
        $this->flushHeaders()->actingAs($this->sa3)->getJson('/api/v1/backups')->assertStatus(403);
    }

    public function test_ac36_no_rows(): void
    {
        $res = $this->sa()->getJson('/api/v1/backups')->assertOk();
        $this->assertSame([], $res->json('data'));
        $this->assertNull($res->json('summary.last_success_at'));
        $this->assertNull($res->json('summary.latest'));
    }

    public function test_ac37_last_success_age(): void
    {
        $this->make('2026-11-16 22:30:00');
        $this->freeze('2026-11-18 01:30:00'); // 27 h later
        $this->assertSame(Carbon::parse('2026-11-16 22:30:00', 'Asia/Colombo')->toIso8601String(),
            Carbon::parse($this->sa()->getJson('/api/v1/backups')->json('summary.last_success_at'))->setTimezone('Asia/Colombo')->toIso8601String());
    }

    public function test_ac40_status_filter(): void
    {
        $this->make('2026-11-16 01:30:00');
        $this->make('2026-11-17 01:30:00', 'failed');
        $this->assertSame(['failed'], array_column($this->sa()->getJson('/api/v1/backups?status=failed')->assertOk()->json('data'), 'status'));
        $this->assertSame(['succeeded'], array_column($this->sa()->getJson('/api/v1/backups?status=succeeded')->assertOk()->json('data'), 'status'));
        $this->sa()->getJson('/api/v1/backups?status=foo')->assertStatus(422);
    }

    public function test_no_write_routes(): void
    {
        $b = $this->make('2026-11-16 01:30:00');
        foreach (['postJson' => '/api/v1/backups', 'putJson' => "/api/v1/backups/{$b->id}", 'deleteJson' => "/api/v1/backups/{$b->id}"] as $m => $url) {
            $this->assertContains($this->sa()->{$m}($url)->status(), [404, 405]);
        }
        $this->assertSame(1, Backup::count());
    }

    public function test_year_end_label_and_pagination(): void
    {
        for ($i = 0; $i < 51; $i++) {
            $this->make(Carbon::parse('2026-09-01 01:30:00')->addDays($i)->format('Y-m-d H:i:s'));
        }
        Backup::orderBy('id')->first()->update(['kept_as' => 'year_end', 'fiscal_year' => 2025]);
        $p1 = $this->sa()->getJson('/api/v1/backups')->assertOk();
        $this->assertCount(50, $p1->json('data'));
        $this->assertSame(2, $p1->json('meta.last_page'));
        $p2 = $this->sa()->getJson('/api/v1/backups?page=2')->json('data');
        $this->assertSame('FY 2025-26', $p2[0]['fiscal_year_label']);
    }
}
