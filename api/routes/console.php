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
// Review M1/M5: the schedule mutexes use the backup lock store (default `file`), not the database cache, so a database
// outage cannot stop the run that would report it. With more than one app server use a shared store (redis).
Schedule::useCache((string) config('backup.lock_store') ?: null);
Schedule::command('backup:run --scheduled')->description('Daily backup')
    ->dailyAt((string) config('backup.time', '01:30'))->timezone($tz)->withoutOverlapping(360)->onOneServer();
Schedule::command('backup:verify --latest')->description('Weekly restore test')
    ->weeklyOn(0, '03:00')->timezone($tz)->withoutOverlapping(360)->onOneServer();
// Review F7: monthly, first Sunday 04:00. Cron cannot say "first Sunday" (day-of-month and day-of-week are OR-ed).
Schedule::command('backup:verify --oldest-offsite')->description('Monthly restore test of the oldest off-server archive (first Sunday)')
    ->weeklyOn(0, '04:00')->timezone($tz)->when(fn () => BusinessDate::now()->day <= 7)->withoutOverlapping(360)->onOneServer();

// AG-087 background exports (docs/specs/background-exports.md section 5 "Schedule"): expire files 24 h after ready,
// fail stale exports, remove orphan files.
Schedule::command('exports:prune')->description('Expire background exports')->hourly()->withoutOverlapping(30)->onOneServer();

// AG-051 R8: transfer approval requests pending for 30 days expire (posts nothing).
Schedule::command('transfers:expire-approvals')->description('Expire transfer approval requests')
    ->dailyAt('00:30')->timezone($tz)->withoutOverlapping(60)->onOneServer();

// AG-094 R11: in-app notifications older than 90 days are deleted (the one hard delete of the item, notifications table
// only; see App\Console\Commands\NotificationsPrune).
Schedule::command('notifications:prune')->description('Delete in-app notifications older than 90 days')
    ->dailyAt('02:30')->timezone($tz)->withoutOverlapping(60)->onOneServer();
