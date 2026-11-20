<?php

/*
 * AG-093 automated daily backups (docs/specs/backups.md section 5 "Config"). The timezone is
 * app.business_timezone (Asia/Colombo). Off-server retention is a bucket lifecycle rule; the app never deletes there.
 */
return [
    'time' => env('BACKUP_TIME', '01:30'),
    'verify_day_time' => 'Sun 03:00',
    'verify_oldest_day_time' => '1st Sun 04:00',

    'local_disk' => 'backups',
    // Names a disk in config/filesystems.php (normally `backups_offsite`); empty = not configured.
    'offsite_disk' => env('BACKUP_OFFSITE_DISK', ''),

    // AES-256 archive password, at least 20 characters, separate from APP_KEY. Production refuses to run without it.
    'password' => env('BACKUP_ARCHIVE_PASSWORD', ''),
    // Earlier passwords (comma-separated), tried by backup:verify after the current one (R12, review F7).
    'old_passwords' => array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_ARCHIVE_OLD_PASSWORDS', ''))))),
    'min_password_length' => 20,

    // Review M1: where alerts go when the Super Admins cannot be read (database down). Comma-separated emails.
    'alert_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_ALERT_EMAILS', ''))))),
    // Review M1: the run and verify locks (and the backup schedule's mutexes) use this cache store, so a database
    // outage does not stop the run before it can alert. `file` needs no database.
    'lock_store' => env('BACKUP_LOCK_STORE', 'file'),
    // Review M5: how long backup:verify waits for another restore test to finish before giving up.
    'verify_wait_seconds' => 7200,

    'keep_daily' => 30,
    'keep_monthly' => 12,
    'min_keep' => 7,
    'keep_year_end_months' => 13,
    'year_end_years' => 8,

    'verify_connection' => 'backup_verify',
    'mysqldump_path' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    'mysql_path' => env('BACKUP_MYSQL', 'mysql'),

    // Working folders (mode 0700), emptied in `finally`.
    'temp_path' => env('BACKUP_TEMP_PATH', storage_path('app/backup-temp')),

    // R1: a run holds the lock for at most this long; older `running` rows are marked failed.
    'stale_hours' => 6,
    // R1 precheck: free space must be 2x the last succeeded archive, or this on the first run.
    'first_run_free_bytes' => 1024 * 1024 * 1024,
    // S1 banner and the closing warning (R15).
    'max_age_hours' => 26,
    'verify_max_age_days' => 8,
];
