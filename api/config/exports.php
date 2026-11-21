<?php

/*
 * AG-087 background exports (docs/specs/background-exports.md section 5 "Config"). Tests may lower these.
 * Changing a limit is an operational decision (accounting review finding 6); PDF keeps its own cap of 1,000 rows
 * (ReportService::PDF_EXPORT_CAP) and is never queued.
 */
return [
    // Excel exports with more rows than this are queued (exactly this many download at once, R1).
    'sync_rows' => (int) env('EXPORTS_SYNC_ROWS', 5000),
    // Above this a request is refused (422); a queued export that grew past it fails with `over_limit` (R4).
    'max_rows' => (int) env('EXPORTS_MAX_ROWS', 100000),
    // A finished file larger than this is deleted and the export fails with `too_large` (R8).
    'max_bytes' => (int) env('EXPORTS_MAX_BYTES', 50 * 1024 * 1024),
    // Files can be downloaded for this long after they are ready (R6, R10).
    'ttl_hours' => (int) env('EXPORTS_TTL_HOURS', 24),
    // Exports `queued` or `running` at the same time per user (R7).
    'per_user_active' => (int) env('EXPORTS_PER_USER_ACTIVE', 2),
    // A `queued` or `running` export older than this is failed by exports:prune (`stale`, R10).
    'stale_minutes' => (int) env('EXPORTS_STALE_MINUTES', 120),
    // Email the requester when an export is ready or has failed (Q5: one global switch).
    'email' => (bool) env('EXPORTS_EMAIL', true),
    // The Exports page lists the user's own exports of this many days (S2).
    'list_days' => 30,
];
