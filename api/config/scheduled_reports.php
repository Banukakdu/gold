<?php

/*
 * AG-088 scheduled email reports (docs/specs/scheduled-reports.md section 5 "Config"). Tests may lower these.
 * Changing a limit is an operational decision (accounting review: limits confirmed, A5 late window per frequency).
 */
return [
    // R7: a PDF larger than this is not attached; the email carries a sign-in link instead. 5 MB + base64 stays < 7 MB.
    'attach_max_bytes' => (int) env('SCHEDULED_REPORTS_ATTACH_MAX_BYTES', 5 * 1024 * 1024),
    // S2: recipients per schedule (1..max_recipients).
    'max_recipients' => (int) env('SCHEDULED_REPORTS_MAX_RECIPIENTS', 20),
    // R11: active schedules at most (paused and archived do not count).
    'max_active' => (int) env('SCHEDULED_REPORTS_MAX_ACTIVE', 50),
    // R4 (accounting review A5): a planned time older than this when the dispatcher sees it is recorded as missed.
    'late_minutes' => [
        'daily' => (int) env('SCHEDULED_REPORTS_LATE_DAILY', 360),
        'weekly' => (int) env('SCHEDULED_REPORTS_LATE_WEEKLY', 1440),
    ],
    // S4 (accounting review A5): a run started more than this many minutes after planned_at says "Sent late".
    'sent_late_minutes' => 60,
    // R4: a run `queued` or `running` for more than this many minutes is failed as `stale`.
    'stale_run_minutes' => 120,
    // R12: a run's PDF is deleted this many days after the run finished (when no delivery is still queued).
    'keep_file_days' => (int) env('SCHEDULED_REPORTS_KEEP_FILE_DAYS', 7),
    // R10: runs in a row that are failed, missed or "Nothing sent" before the schedule is paused by the system.
    'failures_before_pause' => (int) env('SCHEDULED_REPORTS_FAILURES_BEFORE_PAUSE', 3),
    // R4: a delivery still `queued` after this many hours is failed as `stale`.
    'delivery_stale_hours' => (int) env('SCHEDULED_REPORTS_DELIVERY_STALE_HOURS', 24),
    // Review S1: a delivery `sending` (claimed by a mail job) for longer than this is failed as `unknown` ("may have
    // been sent"), never sent again. Well above the time one SMTP send of a 5 MB attachment can take.
    'sending_stale_minutes' => (int) env('SCHEDULED_REPORTS_SENDING_STALE_MINUTES', 60),
    // S5: also email the owner (and the Super Admins for a system pause) about failed, missed and paused runs.
    'email_owner' => (bool) env('SCHEDULED_REPORTS_EMAIL_OWNER', true),
];
