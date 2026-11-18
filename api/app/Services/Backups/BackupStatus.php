<?php

namespace App\Services\Backups;

use App\Models\Backup;
use Carbon\CarbonInterface;

/** S1 summary (section 5) and the closing warning (R15, review F9). Read-only. */
final class BackupStatus
{
    public static function summary(): array
    {
        $last = Backup::succeeded()->orderByDesc('started_at')->orderByDesc('id')->first();
        $latest = Backup::orderByDesc('started_at')->orderByDesc('id')->first();
        $verified = Backup::whereNotNull('verify_status')->orderByDesc('verified_at')->orderByDesc('id')->first();

        return [
            'last_success_at' => $last?->started_at?->toIso8601String(),
            'last_success' => $last ? ['started_at' => $last->started_at->toIso8601String(), 'size_bytes' => $last->size_bytes,
                'offsite_status' => $last->offsite_status] : null,
            'latest' => $latest ? ['filename' => $latest->filename, 'status' => $latest->status, 'failed_stage' => $latest->failed_stage,
                'error' => self::clean($latest->error), 'offsite_status' => $latest->offsite_status, 'offsite_error' => self::clean($latest->offsite_error)] : null,
            'offsite_configured' => (string) config('backup.offsite_disk') !== '',
            'offsite_required' => app()->environment('production'),
            'encrypted_configured' => (string) config('backup.password') !== '',
            'schedule' => ['backup' => (string) config('backup.time'), 'verify' => (string) config('backup.verify_day_time'),
                'verify_oldest' => (string) config('backup.verify_oldest_day_time'), 'timezone' => (string) config('app.business_timezone')],
            'retention' => ['daily' => (int) config('backup.keep_daily'), 'monthly' => (int) config('backup.keep_monthly'),
                'min_keep' => (int) config('backup.min_keep'), 'year_end_years' => (int) config('backup.year_end_years')],
            'last_verify' => $verified ? ['at' => $verified->verified_at?->toIso8601String(), 'status' => $verified->verify_status] : null,
        ];
    }

    /** R15: null when backups are healthy, else one sentence ending "Fix backups before closing." */
    public static function warning(): ?string
    {
        $last = Backup::succeeded()->max('started_at');
        $parts = [];
        if ($last === null) {
            $parts[] = 'No backup has completed yet.';
        } else {
            $at = now()->parse($last);
            if ($at->lt(now()->subHours((int) config('backup.max_age_hours', 26)))) {
                $parts[] = 'Last successful backup '.self::age($at).' ago.';
            }
        }
        $days = (int) config('backup.verify_max_age_days', 8);
        if (! Backup::where('verify_status', 'passed')->where('verified_at', '>=', now()->subDays($days))->exists()) {
            $parts[] = "No restore test has passed in the last {$days} days.";
        }

        return $parts ? implode(' ', $parts).' Fix backups before closing.' : null;
    }

    public static function age(CarbonInterface $at): string
    {
        $hours = (int) floor($at->diffInHours(now(), true));
        if ($hours >= 48) {
            $d = intdiv($hours, 24);

            return "{$d} days";
        }

        return $hours === 1 ? '1 hour' : "{$hours} hours";
    }

    /** Section 5: API values carry no filesystem path, disk name or URL. */
    public static function clean(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $disks = array_filter([(string) config('backup.offsite_disk'), 'backups_offsite', (string) config('backup.local_disk')]);
        $text = preg_replace('#[a-z][a-z0-9+.-]*://\S+#i', '[url]', $text) ?? $text;
        $text = preg_replace('#(?<![\w.-])(?:[A-Za-z]:)?(?:[\\\\/][^\s\\\\/:"\']+){2,}[\\\\/]?#', '[path]', $text) ?? $text;
        foreach ($disks as $d) {
            $text = preg_replace('/(?<![\w-])'.preg_quote($d, '/').'(?![\w-])/', '[disk]', $text) ?? $text;
        }

        return $text;
    }
}
