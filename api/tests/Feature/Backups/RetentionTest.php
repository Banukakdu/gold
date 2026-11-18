<?php

namespace Tests\Feature\Backups;

use App\Exceptions\ImmutableRecordException;
use App\Models\AuditLog;
use App\Models\Backup;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/** Section 7 "Retention" (AC 19-23) and review F1 (AC 42). */
class RetentionTest extends BackupsTestCase
{
    private function row(Carbon $colomboStart, string $keptAs, array $extra = []): Backup
    {
        $name = 'ag-backup-'.$colomboStart->copy()->setTimezone('Asia/Colombo')->format('Ymd-His').'.zip';
        foreach ([$name, "{$name}.sha256", "{$name}.manifest.json"] as $f) {
            Storage::disk('backups')->put($f, 'x');
        }

        return Backup::create($extra + ['filename' => $name, 'status' => 'succeeded', 'triggered_by' => 'scheduler', 'started_at' => $colomboStart->copy()->utc(),
            'finished_at' => $colomboStart->copy()->utc()->addMinute(), 'db_driver' => 'sqlite', 'encrypted' => true, 'kept_as' => $keptAs,
            'size_bytes' => 1, 'sha256' => str_repeat('a', 64), 'files_count' => 0, 'offsite_status' => 'not_configured']);
    }

    private function prune(): int
    {
        return $this->artisan_('backup:prune')[0];
    }

    public function test_ac19_only_the_pruner_deletes_on_backup_disks(): void
    {
        $offenders = [];
        foreach (File::allFiles(app_path()) as $f) {
            $src = $f->getContents();
            $mentionsBackupDisk = preg_match("/backup\\.local_disk|backup\\.offsite_disk|'backups(_offsite)?'|OffsiteStore|->disk\\(\\)/", $src);
            // Review minor: ANY delete call in a file that touches a backup disk counts, however the disk is reached.
            $deletesOnDisk = preg_match('/->\s*(delete|deleteDirectory)\s*\(/i', $src);
            if ($mentionsBackupDisk && $deletesOnDisk) {
                $offenders[] = $f->getFilename();
            }
        }
        sort($offenders);
        $this->assertSame(['BackupPruner.php', 'LocalStore.php'], $offenders);
        $store = file_get_contents(app_path('Services/Backups/LocalStore.php'));
        $this->assertSame(1, preg_match_all('/->\s*delete\s*\(/', $store), 'LocalStore only removes the files of its own failed write');
        $pruner = file_get_contents(app_path('Services/Backups/BackupPruner.php'));
        $this->assertDoesNotMatchRegularExpression('/offsite_disk|OffsiteStore|backups_offsite/', $pruner);
        $this->assertDoesNotMatchRegularExpression('/->\s*delete|deleteDirectory/i', file_get_contents(app_path('Services/Backups/OffsiteStore.php')));
    }

    public function test_ac20_dailies_older_than_30_days_are_pruned(): void
    {
        $today = Carbon::parse('2026-11-18 01:30:00', 'Asia/Colombo');
        $rows = [];
        for ($i = 39; $i >= 0; $i--) {
            $d = $today->copy()->subDays($i);
            $rows[$i] = $this->row($d, $d->day === 1 ? 'monthly' : 'daily');
        }
        $this->assertSame(0, $this->prune());
        foreach ($rows as $age => $b) {
            $b->refresh();
            $shouldGo = $b->kept_as === 'daily' && $age > 30;
            $this->assertSame($shouldGo, $b->local_pruned_at !== null, "{$b->filename} age {$age}");
            $this->assertSame(! $shouldGo, Storage::disk('backups')->exists($b->filename));
            $this->assertSame(! $shouldGo, Storage::disk('backups')->exists($b->filename.'.sha256'));
            $this->assertSame($shouldGo ? 1 : 0, AuditLog::where('event', 'backup.pruned')->where('auditable_id', $b->id)->count());
        }
        $this->assertSame(40, Backup::count());
        $this->assertSame('monthly', Backup::where('filename', 'ag-backup-20261101-013000.zip')->value('kept_as'));
        $this->assertNull(Backup::where('filename', 'ag-backup-20261101-013000.zip')->value('local_pruned_at'));
        $a = AuditLog::where('event', 'backup.pruned')->first();
        $this->assertSame('daily_30', $a->new_values['rule']);
        $this->assertNull($a->user_id);
    }

    public function test_ac21_min_keep_protects_the_last_seven(): void
    {
        foreach (range(1, 5) as $i) {
            $this->row(Carbon::parse('2026-09-19 01:30:00', 'Asia/Colombo')->addMinutes($i), 'daily');
        }
        $this->prune();
        $this->assertSame(0, Backup::whereNotNull('local_pruned_at')->count());
    }

    public function test_ac22_monthly_older_than_12_months(): void
    {
        $old = $this->row(Carbon::parse('2025-10-01 01:30:00', 'Asia/Colombo'), 'monthly');
        foreach (range(1, 7) as $i) {
            $this->row(Carbon::parse('2026-11-10 01:30:00', 'Asia/Colombo')->addDays($i - 1), 'daily');
        }
        $this->prune();
        $this->assertNotNull($old->fresh()->local_pruned_at);
        $this->assertSame('monthly_12', AuditLog::where('event', 'backup.pruned')->sole()->new_values['rule']);
    }

    public function test_ac42_year_end_is_kept_until_copied_off_server(): void
    {
        $nc = $this->row(Carbon::parse('2025-09-11 01:30:00', 'Asia/Colombo'), 'year_end', ['fiscal_year' => 2024]);
        $failed = $this->row(Carbon::parse('2025-09-12 01:30:00', 'Asia/Colombo'), 'year_end', ['fiscal_year' => 2024, 'offsite_status' => 'failed']);
        $copied = $this->row(Carbon::parse('2025-09-13 01:30:00', 'Asia/Colombo'), 'year_end', ['fiscal_year' => 2024, 'offsite_status' => 'copied']);
        $young = $this->row(Carbon::parse('2025-11-13 01:30:00', 'Asia/Colombo'), 'year_end', ['fiscal_year' => 2024, 'offsite_status' => 'copied']);
        foreach (range(1, 7) as $i) {
            $this->row(Carbon::parse('2026-11-10 01:30:00', 'Asia/Colombo')->addDays($i - 1), 'daily');
        }
        $this->prune();
        $this->assertNull($nc->fresh()->local_pruned_at);
        $this->assertNull($failed->fresh()->local_pruned_at);
        $this->assertNotNull($copied->fresh()->local_pruned_at);
        $this->assertNull($young->fresh()->local_pruned_at, '12 months old: inside the 13-month rule');
        $this->assertSame('year_end_13', AuditLog::where('event', 'backup.pruned')->sole()->new_values['rule']);
        $this->assertSame(8, $this->sa()->getJson('/api/v1/backups')->json('summary.retention.year_end_years'));
    }

    public function test_ac23_rows_cannot_be_deleted(): void
    {
        $b = $this->row(Carbon::parse('2026-11-17 01:30:00', 'Asia/Colombo'), 'daily');
        try {
            $b->delete();
            $this->fail('model delete was allowed');
        } catch (ImmutableRecordException) {
        }
        try {
            DB::table('backups')->delete();
            $this->fail('DB delete was allowed');
        } catch (QueryException $e) {
            $this->assertStringContainsString('DELETE refused', $e->getMessage());
        }
        $this->assertSame(1, Backup::count());
    }
}
