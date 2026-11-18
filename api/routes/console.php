<?php

use App\Support\BusinessDate;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// AG-093 backups (docs/specs/backups.md section 5 "Schedule"). Cron must run `php artisan schedule:run` every minute.
$tz = BusinessDate::timezone();
Schedule::command('backup:run --scheduled')->description('Daily backup')
    ->dailyAt((string) config('backup.time', '01:30'))->timezone($tz)->withoutOverlapping(360)->onOneServer();
Schedule::command('backup:verify --latest')->description('Weekly restore test')
    ->weeklyOn(0, '03:00')->timezone($tz)->withoutOverlapping(360);
// Review F7: monthly, first Sunday 04:00. Cron cannot say "first Sunday" (day-of-month and day-of-week are OR-ed).
Schedule::command('backup:verify --oldest-offsite')->description('Monthly restore test of the oldest off-server archive (first Sunday)')
    ->weeklyOn(0, '04:00')->timezone($tz)->when(fn () => BusinessDate::now()->day <= 7)->withoutOverlapping(360);
