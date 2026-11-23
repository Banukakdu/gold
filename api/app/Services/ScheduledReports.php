<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\ReportSchedule;
use App\Models\ReportScheduleDelivery;
use App\Models\ReportScheduleRecipient;
use App\Models\ReportScheduleRun;
use App\Models\User;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * AG-088 scheduled email reports (docs/specs/scheduled-reports.md): maintenance by Super Admins (S1-S3, section 5),
 * the times and periods (R2), the owner (R3), the recipient check (R5) and the resources. A schedule only reads: no
 * method here or in ScheduledReportRuns writes `transactions`, `ledger_entries` or a balance.
 *
 * Every change writes one `report_schedule.*` audit row in the same transaction as the change (R13). Saves lock the
 * schedule rows first (a locking read), so two name or limit checks never race (R11).
 */
class ScheduledReports
{
    public const NOT_FOUND = 'Schedule not found.';

    public const NAME_TAKEN = 'A schedule with this name already exists.';

    public const NAME_LENGTH = 'The name must be 3 to 100 characters.';

    public const REPORT = 'Choose one of the listed reports.';

    public const TIME = 'Choose a time on the hour or half hour.';

    public const WEEKDAY = 'Choose the day of the week.';

    public const SELECT_BRANCH = 'Select at least one branch.';

    public const UNKNOWN_BRANCH = 'Unknown branch.';

    public const UNKNOWN_USER = 'Unknown user.';

    public const RECIPIENTS_MIN = 'Choose at least one recipient.';

    public const ARCHIVED_EDIT = 'Restore this schedule before editing it.';

    public const NOT_ACTIVE = 'This schedule is not active.';

    public const NOT_PAUSED = 'This schedule is not paused.';

    public const ALREADY_ARCHIVED = 'This schedule is already archived.';

    public const NOT_ARCHIVED = 'This schedule is not archived.';

    public const NOBODY_CAN_RECEIVE = 'None of the recipients can receive this report. Change the recipients first.';

    /** R5 reasons (`deliveries.reason` and S1/S3). `{code}` is the schedule's single branch. */
    public const REASONS = [
        'inactive' => 'Inactive user',
        'super_admin_only' => 'Only Super Admins can receive reports covering more than one branch',
        'not_member' => 'Not a member of {code}',
        'missing_permission' => 'No View reports or Export permission in {code}',
        'schedule_paused' => 'Schedule paused or archived before sending',
        'email_changed' => 'Email address changed after the report was built',
        'run_failed' => 'The report run failed',
        // Delivery failures (R8, R9, R4).
        'mail_error' => 'The mail server did not accept the email.',
        'damaged' => 'The report file was changed after it was built; nothing was sent.',
        'missing' => 'The report file was missing; nothing was sent.',
        'stale' => 'The email was not sent in time.',
        'unknown' => 'The email may have been sent, but the mail server did not confirm it. Check with the recipient.',
    ];

    public const PAUSED_REASONS = [
        'manual' => 'paused by a Super Admin',
        'owner_lost_access' => 'the owner is no longer an active Super Admin',
        'repeated_failures' => 'it failed several times in a row',
    ];

    /** Fields compared and audited on PUT (R13); recipients appear as `recipient_ids`. */
    public const AUDIT_FIELDS = ['name', 'report', 'params', 'branch_ids', 'frequency', 'weekday', 'send_time'];

    // ------------------------------------------------------------------ times and periods (R2)

    /** The next local send time strictly after $after, in UTC (07:00 Colombo = 01:30 UTC). */
    public static function nextRunAt(string $frequency, ?int $weekday, string $sendTime, CarbonInterface $after): CarbonImmutable
    {
        $local = CarbonImmutable::instance($after)->setTimezone(BusinessDate::timezone());
        [$h, $m] = array_map('intval', explode(':', $sendTime));
        $at = $local->setTime($h, $m, 0);
        if ($frequency === ReportSchedule::WEEKLY) {
            $at = $at->addDays(((int) $weekday - $at->dayOfWeekIso + 7) % 7);
        }
        while ($at->lte($local)) {
            $at = $frequency === ReportSchedule::WEEKLY ? $at->addWeek() : $at->addDay();
        }

        return $at->utc();
    }

    /**
     * The period of a run planned at $planned (taken from the planned time, never from when the worker runs it).
     * Daily: the previous calendar day (accounting review A1). Weekly: the last complete Monday-Sunday week.
     *
     * @return array{0: string, 1: string} from, to (Y-m-d)
     */
    public static function period(string $frequency, CarbonInterface $planned): array
    {
        $day = CarbonImmutable::instance($planned)->setTimezone(BusinessDate::timezone())->startOfDay();
        if ($frequency !== ReportSchedule::WEEKLY) {
            $d = $day->subDay()->toDateString();

            return [$d, $d];
        }
        $to = $day->subDay();
        while ($to->dayOfWeekIso !== 7) {
            $to = $to->subDay();
        }

        return [$to->subDays(6)->toDateString(), $to->toDateString()];
    }

    /** GET /preview-time (S2 live line). */
    public function previewTime(array $input): array
    {
        $d = validator($input, [
            'frequency' => ['required', Rule::in([ReportSchedule::DAILY, ReportSchedule::WEEKLY])],
            'weekday' => 'nullable|required_if:frequency,weekly|integer|between:1,7',
            'send_time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):(00|30)$/'],
        ], ['send_time.regex' => self::TIME, 'weekday.required_if' => self::WEEKDAY])->validate();
        $next = self::nextRunAt($d['frequency'], isset($d['weekday']) ? (int) $d['weekday'] : null, $d['send_time'], now());
        [$from, $to] = self::period($d['frequency'], $next);

        return ['next_run_at' => $next->toIso8601ZuluString(), 'period_from' => $from, 'period_to' => $to];
    }

    // ------------------------------------------------------------------ recipients (R5)

    /**
     * R5: null when $user can receive a report of this branch scope ($branchIds: the schedule's explicit branches,
     * [] = All branches), else the reason code. Read fresh each time (build and send).
     *
     * @param  int[]  $branchIds
     */
    public function recipientProblem(?User $user, array $branchIds, ?array $index = null): ?string
    {
        if (! $user || $user->status !== 'active') {
            return 'inactive';
        }
        if ($user->is_super_admin) {
            return null;
        }
        if (count($branchIds) !== 1) {
            return 'super_admin_only';
        }
        $branchId = (int) $branchIds[0];
        if (isset($index[$branchId])) {
            // The list screens (review S4): the same rule from sets loaded once per request.
            $i = $index[$branchId];
            if (! isset($i['members'][$user->id])) {
                return 'not_member';
            }

            return isset($i['view'][$user->id], $i['export'][$user->id]) ? null : 'missing_permission';
        }
        if (! $user->isMemberOf($branchId)) {
            return 'not_member';
        }
        $inApp = app(InAppNotifications::class);
        if (! $inApp->holds((int) $user->id, 'report.view', $branchId) || ! $inApp->holds((int) $user->id, 'report.export', $branchId)) {
            return 'missing_permission';
        }

        return null;
    }

    /**
     * Review S4: for each branch, its members and the holders of report.view and report.export, three queries per
     * branch, so a list of schedules or users is checked without a query per recipient. Only for screens: the build and
     * the send read fresh.
     *
     * @param  int[]  $branchIds
     * @return array<int, array{members: array<int, int>, view: array<int, int>, export: array<int, int>}>
     */
    public function recipientIndex(array $branchIds): array
    {
        $inApp = app(InAppNotifications::class);
        $out = [];
        foreach (array_unique(array_map('intval', $branchIds)) as $b) {
            $out[$b] = [
                'members' => array_flip(DB::table('branch_user')->where('branch_id', $b)->pluck('user_id')->map(fn ($v) => (int) $v)->all()),
                'view' => array_flip($inApp->holderIdsOf(['report.view'], $b)),
                'export' => array_flip($inApp->holderIdsOf(['report.export'], $b)),
            ];
        }

        return $out;
    }

    /** The plain text of a reason code, with the branch code filled in. */
    public static function reasonText(?string $code, ?string $branchCode = null): ?string
    {
        if ($code === null) {
            return null;
        }

        return str_replace('{code}', (string) $branchCode, self::REASONS[$code] ?? $code);
    }

    /** GET /recipient-options: every user by name with whether they can receive a report of these branches now. */
    public function recipientOptions(array $input): array
    {
        $ids = $this->branchIdsFrom($input['branches'] ?? []);
        $code = count($ids) === 1 ? Branch::whereKey($ids[0])->value('code') : null;

        $index = count($ids) === 1 ? $this->recipientIndex($ids) : null;

        return User::orderBy('name')->orderBy('id')->get()->map(function (User $u) use ($ids, $code, $index) {
            $problem = $this->recipientProblem($u, $ids, $index);

            return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'is_super_admin' => (bool) $u->is_super_admin,
                'status' => $u->status, 'eligible' => $problem === null, 'reason' => self::reasonText($problem, $code)];
        })->values()->all();
    }

    // ------------------------------------------------------------------ maintenance (S2, R11)

    public function store(array $input, User $actor): ReportSchedule
    {
        return DB::transaction(function () use ($input, $actor) {
            $this->lockAll();
            $d = $this->validated($input);
            $this->assertNameFree($d['name']);
            $this->assertActiveLimit();
            $this->assertNewRecipients($d['recipient_ids'], [], $d['branch_ids']);
            $now = now();
            $s = ReportSchedule::create([
                'name' => $d['name'], 'report' => $d['report'], 'params' => $d['params'], 'branch_ids' => $d['branch_ids'],
                'frequency' => $d['frequency'], 'weekday' => $d['weekday'], 'send_time' => $d['send_time'],
                'status' => ReportSchedule::ACTIVE, 'paused_reason' => null, 'owner_id' => $actor->id, 'created_by' => $actor->id,
                'next_run_at' => self::nextRunAt($d['frequency'], $d['weekday'], $d['send_time'], $now), 'consecutive_failures' => 0,
            ]);
            foreach ($d['recipient_ids'] as $uid) {
                ReportScheduleRecipient::create(['schedule_id' => $s->id, 'user_id' => $uid, 'added_by' => $actor->id, 'added_at' => $now]);
            }
            AuditLog::record('report_schedule.created', $s, null,
                $this->auditValues($s) + ['recipient_ids' => $d['recipient_ids'], 'status' => $s->status, 'owner_id' => $s->owner_id,
                    'next_run_at' => $s->next_run_at->toIso8601ZuluString()], $s->auditBranchId());

            return $s;
        });
    }

    /** PUT: all fields again; only changed fields are audited, and saving the same values writes nothing (R13). */
    public function update(int $id, array $input, User $actor): ReportSchedule
    {
        return DB::transaction(function () use ($id, $input, $actor) {
            $this->lockAll();
            $s = $this->find($id, lock: true);
            abort_if($s->status === ReportSchedule::ARCHIVED, 422, self::ARCHIVED_EDIT);
            $d = $this->validated($input);
            $this->assertNameFree($d['name'], $s->id);
            $current = $this->currentRecipientIds($s);
            $this->assertNewRecipients($d['recipient_ids'], $current, $d['branch_ids']);

            $before = $this->auditValues($s);
            $s->fill(['name' => $d['name'], 'report' => $d['report'], 'params' => $d['params'], 'branch_ids' => $d['branch_ids'],
                'frequency' => $d['frequency'], 'weekday' => $d['weekday'], 'send_time' => $d['send_time']]);
            $after = $this->auditValues($s);
            $changed = array_keys(array_filter($after, fn ($v, $k) => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
            $old = array_intersect_key($before, array_flip($changed));
            $new = array_intersect_key($after, array_flip($changed));
            if ($current !== $d['recipient_ids']) {
                $old['recipient_ids'] = $current;
                $new['recipient_ids'] = $d['recipient_ids'];
            }
            if (! $new) {
                return $s->refresh();
            }
            $now = now();
            $removed = array_diff($current, $d['recipient_ids']);
            if ($removed) {
                ReportScheduleRecipient::where('schedule_id', $s->id)->whereNull('removed_at')->whereIn('user_id', $removed)
                    ->update(['removed_at' => $now, 'removed_by' => $actor->id]);
            }
            foreach (array_diff($d['recipient_ids'], $current) as $uid) {
                ReportScheduleRecipient::create(['schedule_id' => $s->id, 'user_id' => $uid, 'added_by' => $actor->id, 'added_at' => $now]);
            }
            $timing = array_intersect($changed, ['frequency', 'weekday', 'send_time']);
            if ($timing && $s->status === ReportSchedule::ACTIVE && ! ($s->next_run_at && $s->next_run_at->lte($now))) {
                // R2: from the moment of saving. Not audited separately: it follows from the audited fields. Review N7:
                // a planned time already due (the dispatcher has not picked it up yet) is kept; the dispatcher sends or
                // records it, and the times after it follow the new timing.
                $s->next_run_at = self::nextRunAt($s->frequency, $s->weekday, $s->send_time, $now);
            }
            $s->updated_by = $actor->id;
            $s->save();
            AuditLog::record('report_schedule.updated', $s, $old, $new, $s->auditBranchId());

            return $s;
        });
    }

    public function pause(int $id, User $actor): ReportSchedule
    {
        return DB::transaction(function () use ($id, $actor) {
            $s = $this->find($id, lock: true);
            abort_unless($s->status === ReportSchedule::ACTIVE, 422, self::NOT_ACTIVE);
            $old = ['status' => $s->status, 'next_run_at' => $s->next_run_at?->toIso8601ZuluString()];
            $s->forceFill(['status' => ReportSchedule::PAUSED, 'paused_reason' => 'manual', 'next_run_at' => null, 'updated_by' => $actor->id])->save();
            AuditLog::record('report_schedule.paused', $s, $old, ['status' => $s->status, 'paused_reason' => 'manual', 'name' => $s->name], $s->auditBranchId());

            return $s;
        });
    }

    /**
     * R3 / R11: back to active from the next future time (no back-fill). The current list must have someone who can
     * receive; the resuming Super Admin becomes the owner when the owner is no longer an active Super Admin.
     */
    public function resume(int $id, User $actor): ReportSchedule
    {
        return DB::transaction(function () use ($id, $actor) {
            $this->lockAll();
            $s = $this->find($id, lock: true);
            abort_unless($s->status === ReportSchedule::PAUSED, 422, self::NOT_PAUSED);
            $branchIds = $s->branchIds();
            $anyone = User::whereIn('id', $this->currentRecipientIds($s))->get()
                ->contains(fn (User $u) => $this->recipientProblem($u, $branchIds) === null);
            abort_unless($anyone, 422, self::NOBODY_CAN_RECEIVE);
            $this->assertActiveLimit();

            $owner = User::find($s->owner_id);
            if (! $owner || $owner->status !== 'active' || ! $owner->is_super_admin) {
                $oldOwner = $s->owner_id;
                $s->owner_id = $actor->id;
                AuditLog::record('report_schedule.owner_changed', $s, ['owner_id' => $oldOwner], ['owner_id' => $actor->id, 'name' => $s->name], $s->auditBranchId());
            }
            $old = ['status' => $s->status, 'paused_reason' => $s->paused_reason, 'consecutive_failures' => $s->consecutive_failures];
            $s->forceFill([
                'status' => ReportSchedule::ACTIVE, 'paused_reason' => null, 'consecutive_failures' => 0, 'updated_by' => $actor->id,
                'next_run_at' => self::nextRunAt($s->frequency, $s->weekday, $s->send_time, now()),
                // R15: the streak boundary. A run planned before it that settles `not_delivered` later is not counted.
                'resumed_at' => now(),
            ])->save();
            AuditLog::record('report_schedule.resumed', $s, $old, ['status' => $s->status, 'next_run_at' => $s->next_run_at->toIso8601ZuluString(),
                'consecutive_failures' => 0, 'name' => $s->name], $s->auditBranchId());

            return $s;
        });
    }

    public function archive(int $id, array $input, User $actor): ReportSchedule
    {
        $reason = validator($input, ['reason' => 'nullable|string|max:500'])->validate()['reason'] ?? null;
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

        return DB::transaction(function () use ($id, $reason, $actor) {
            $s = $this->find($id, lock: true);
            abort_if($s->status === ReportSchedule::ARCHIVED, 422, self::ALREADY_ARCHIVED);
            $old = ['status' => $s->status, 'next_run_at' => $s->next_run_at?->toIso8601ZuluString()];
            $s->forceFill(['status' => ReportSchedule::ARCHIVED, 'next_run_at' => null, 'archived_at' => now(), 'archived_by' => $actor->id,
                'archive_reason' => $reason, 'updated_by' => $actor->id])->save();
            AuditLog::record('report_schedule.archived', $s, $old, ['status' => $s->status, 'reason' => $reason, 'name' => $s->name], $s->auditBranchId());

            return $s;
        });
    }

    /** Back as paused (R11); the name must be free among the non-archived schedules. */
    public function restore(int $id, User $actor): ReportSchedule
    {
        return DB::transaction(function () use ($id, $actor) {
            $this->lockAll();
            $s = $this->find($id, lock: true);
            abort_unless($s->status === ReportSchedule::ARCHIVED, 422, self::NOT_ARCHIVED);
            $this->assertNameFree($s->name, $s->id);
            $old = ['status' => $s->status, 'archived_at' => $s->archived_at?->toIso8601String(), 'archived_by' => $s->archived_by, 'reason' => $s->archive_reason];
            $s->forceFill(['status' => ReportSchedule::PAUSED, 'paused_reason' => 'manual', 'archived_at' => null, 'archived_by' => null,
                'archive_reason' => null, 'updated_by' => $actor->id])->save();
            AuditLog::record('report_schedule.restored', $s, $old, ['status' => $s->status, 'name' => $s->name], $s->auditBranchId());

            return $s;
        });
    }

    public function find(int $id, bool $lock = false): ReportSchedule
    {
        $s = ReportSchedule::whereKey($id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (! $s) {
            abort(response()->json(['message' => self::NOT_FOUND], 404));
        }

        return $s;
    }

    /** A locking read of every schedule row (and, on MySQL, the gap after them): saves are checked one at a time. */
    private function lockAll(): void
    {
        ReportSchedule::query()->lockForUpdate()->pluck('id');
    }

    // ------------------------------------------------------------------ validation (S2)

    /** @return array{name: string, report: string, params: array, branch_ids: int[], frequency: string, weekday: ?int, send_time: string, recipient_ids: int[]} */
    private function validated(array $input): array
    {
        $max = (int) config('scheduled_reports.max_recipients', 20);
        $d = validator($input, [
            'name' => 'required|string|max:255',
            'report' => ['required', 'string', Rule::in(ReportSchedule::REPORTS)],
            'params' => 'nullable|array',
            'params.type' => ['nullable', Rule::in(['all', 'income', 'expense'])],
            'params.types' => 'nullable|array',
            'params.types.*' => ['string', Rule::in(ReportService::MOVEMENT_TYPES)],
            'branches' => 'nullable|array',
            'branches.*' => 'integer|distinct',
            'frequency' => ['required', Rule::in([ReportSchedule::DAILY, ReportSchedule::WEEKLY])],
            'weekday' => 'nullable|required_if:frequency,weekly|integer|between:1,7',
            'send_time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):(00|30)$/'],
            'recipient_ids' => "required|array|min:1|max:{$max}",
            'recipient_ids.*' => 'integer|distinct',
        ], [
            'report.in' => self::REPORT,
            'send_time.regex' => self::TIME,
            'weekday.required_if' => self::WEEKDAY,
            'recipient_ids.required' => self::RECIPIENTS_MIN,
            'recipient_ids.min' => self::RECIPIENTS_MIN,
            'recipient_ids.max' => "Choose at most {$max} recipients.",
        ])->validate();

        // Trimmed, inner spaces collapsed, 3-100 characters.
        $d['name'] = trim((string) preg_replace('/\s+/u', ' ', $d['name']));
        $length = mb_strlen($d['name']);
        if ($length < 3 || $length > 100) {
            throw ValidationException::withMessages(['name' => self::NAME_LENGTH]);
        }

        $branchIds = $this->branchIdsFrom($d['branches'] ?? []);
        if ($d['report'] === 'cash-book' && ! $branchIds) {
            throw ValidationException::withMessages(['branches' => self::SELECT_BRANCH]);
        }

        $params = match ($d['report']) {
            'income-expense' => ['type' => $d['params']['type'] ?? 'all'],
            'transfers' => ['types' => array_values(array_intersect(ReportService::MOVEMENT_TYPES, array_unique($d['params']['types'] ?? [])))],
            default => [],
        };

        $recipients = array_values(array_unique(array_map('intval', $d['recipient_ids'])));
        sort($recipients);
        if (User::whereIn('id', $recipients)->count() !== count($recipients)) {
            throw ValidationException::withMessages(['recipient_ids' => self::UNKNOWN_USER]);
        }

        return [
            'name' => $d['name'], 'report' => $d['report'], 'params' => $params, 'branch_ids' => $branchIds,
            'frequency' => $d['frequency'],
            // Ignored for daily (S2).
            'weekday' => $d['frequency'] === ReportSchedule::WEEKLY ? (int) $d['weekday'] : null,
            'send_time' => $d['send_time'], 'recipient_ids' => $recipients,
        ];
    }

    /** @return int[] sorted, every one an existing branch (422 "Unknown branch." otherwise) */
    private function branchIdsFrom(mixed $branches): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter((array) $branches, fn ($v) => $v !== null && $v !== ''))));
        sort($ids);
        if ($ids && Branch::whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['branches' => self::UNKNOWN_BRANCH]);
        }

        return $ids;
    }

    /** Case-insensitive among the schedules that are not archived (portable LOWER(), as expense templates). */
    private function assertNameFree(string $name, ?int $exceptId = null): void
    {
        $taken = ReportSchedule::where('status', '!=', ReportSchedule::ARCHIVED)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($exceptId, fn ($q, $id) => $q->whereKeyNot($id))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => self::NAME_TAKEN]);
        }
    }

    /** R11: at most `max_active` active schedules (paused and archived do not count). */
    private function assertActiveLimit(): void
    {
        $max = (int) config('scheduled_reports.max_active', 50);
        if (ReportSchedule::where('status', ReportSchedule::ACTIVE)->count() >= $max) {
            abort(response()->json(['message' => "You can have at most {$max} active schedules."], 422));
        }
    }

    /**
     * R5 at save: users added now must be able to receive the report. Users already on the list are kept even when
     * they cannot receive now (flagged, skipped at send).
     *
     * @param  int[]  $ids
     * @param  int[]  $current
     * @param  int[]  $branchIds
     */
    private function assertNewRecipients(array $ids, array $current, array $branchIds): void
    {
        $added = array_values(array_diff($ids, $current));
        if (! $added) {
            return;
        }
        $code = count($branchIds) === 1 ? Branch::whereKey($branchIds[0])->value('code') : null;
        $errors = [];
        foreach (User::whereIn('id', $added)->orderBy('name')->get() as $u) {
            if ($problem = $this->recipientProblem($u, $branchIds)) {
                $errors[] = "{$u->name} cannot receive this report: ".self::reasonText($problem, $code).'.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages(['recipient_ids' => $errors]);
        }
    }

    /** @return int[] sorted ids of the users on the list now */
    public function currentRecipientIds(ReportSchedule $s): array
    {
        $ids = ReportScheduleRecipient::where('schedule_id', $s->id)->whereNull('removed_at')->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->all();
        sort($ids);

        return array_values($ids);
    }

    /** The audited fields in one canonical form (params keys sorted, branch ids sorted ints). */
    private function auditValues(ReportSchedule $s): array
    {
        $params = (array) $s->params;
        ksort($params);

        return ['name' => $s->name, 'report' => $s->report, 'params' => $params, 'branch_ids' => $s->branchIds(),
            'frequency' => $s->frequency, 'weekday' => $s->weekday === null ? null : (int) $s->weekday, 'send_time' => $s->send_time];
    }

    // ------------------------------------------------------------------ presentation (section 5)

    /** @return Collection<int, ReportSchedule> */
    public function list(string $status): Collection
    {
        return ReportSchedule::query()
            ->when($status === 'current', fn ($q) => $q->whereIn('status', [ReportSchedule::ACTIVE, ReportSchedule::PAUSED]))
            ->when($status === 'archived', fn ($q) => $q->where('status', ReportSchedule::ARCHIVED))
            ->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Review S4: the list resource with a fixed number of queries: recipients, users, last runs, their delivery counts
     * and the recipient index of the single-branch schedules, each loaded once.
     *
     * @param  Collection<int, ReportSchedule>  $schedules
     */
    public function presentMany(Collection $schedules): array
    {
        $ids = $schedules->pluck('id')->all();
        if (! $ids) {
            return [];
        }
        $recipients = ReportScheduleRecipient::whereIn('schedule_id', $ids)->whereNull('removed_at')->orderBy('id')->get()->groupBy('schedule_id');
        $userIds = $recipients->collapse()->pluck('user_id')
            ->concat($schedules->flatMap(fn (ReportSchedule $s) => [$s->owner_id, $s->created_by, $s->updated_by, $s->archived_by]))
            ->filter()->unique()->values()->all();
        $latest = ReportScheduleRun::whereIn('schedule_id', $ids)->selectRaw('schedule_id, MAX(planned_at) as last_planned')->groupBy('schedule_id');
        $lastRuns = ReportScheduleRun::joinSub($latest, 'lr', fn ($j) => $j->on('lr.schedule_id', '=', 'report_schedule_runs.schedule_id')
            ->on('lr.last_planned', '=', 'report_schedule_runs.planned_at'))->select('report_schedule_runs.*')->get()->keyBy('schedule_id');
        $counts = $this->countsFor($lastRuns->pluck('id')->all());
        $single = $schedules->map(fn (ReportSchedule $s) => $s->branchIds())->filter(fn ($b) => count($b) === 1)->map(fn ($b) => $b[0])->values()->all();
        $pre = [
            'branches' => Branch::all()->keyBy('id'),
            'users' => User::whereIn('id', $userIds)->get()->keyBy('id'),
            'index' => $this->recipientIndex($single),
        ];

        return $schedules->map(fn (ReportSchedule $s) => $this->present($s, $pre + [
            'recipients' => $recipients[$s->id] ?? collect(),
            'last' => $lastRuns[$s->id] ?? null,
            'counts' => isset($lastRuns[$s->id]) ? $counts[$lastRuns[$s->id]->id] : null,
        ]))->values()->all();
    }

    /**
     * The `schedule` resource. $pre: data loaded once for a list (presentMany).
     *
     * @param  array{branches?: Collection, users?: Collection, index?: array, recipients?: Collection, last?: ?ReportScheduleRun, counts?: ?array}  $pre
     */
    public function present(ReportSchedule $s, array $pre = []): array
    {
        $branches = $pre['branches'] ?? Branch::all()->keyBy('id');
        $ids = $s->branchIds();
        $code = count($ids) === 1 ? ($branches[$ids[0]]->code ?? null) : null;
        $recipientRows = $pre['recipients'] ?? ReportScheduleRecipient::where('schedule_id', $s->id)->whereNull('removed_at')->orderBy('id')->get();
        $userIds = [...$recipientRows->pluck('user_id')->all(), $s->owner_id, $s->created_by, $s->updated_by, $s->archived_by];
        $users = $pre['users'] ?? User::whereIn('id', array_filter($userIds))->get()->keyBy('id');
        $index = $pre['index'] ?? null;
        $name = fn (?int $id) => $id ? ['id' => $id, 'name' => $users[$id]->name ?? "#{$id}"] : null;
        $next = $s->status === ReportSchedule::ACTIVE && $s->next_run_at ? self::period($s->frequency, $s->next_run_at) : null;

        $recipients = $recipientRows->map(function (ReportScheduleRecipient $r) use ($users, $ids, $code, $index) {
            $u = $users[$r->user_id] ?? null;
            $problem = $this->recipientProblem($u, $ids, $index);

            return ['id' => $r->user_id, 'name' => $u?->name, 'email' => $u?->email, 'status' => $u?->status,
                'is_super_admin' => (bool) $u?->is_super_admin, 'eligible' => $problem === null, 'reason' => self::reasonText($problem, $code)];
        })->sortBy(fn ($r) => mb_strtolower((string) $r['name']))->values()->all();

        $last = array_key_exists('last', $pre) ? $pre['last'] : ReportScheduleRun::where('schedule_id', $s->id)->orderByDesc('planned_at')->first();

        return [
            'id' => $s->id,
            'name' => $s->name,
            'report' => $s->report,
            'title' => ReportExporter::TITLES[$s->report] ?? $s->report,
            'params' => (array) $s->params,
            'params_label' => self::paramsLabel($s->report, (array) $s->params),
            'branches' => array_values(array_map(fn ($id) => ['id' => $id, 'code' => $branches[$id]->code ?? "#{$id}",
                'status' => $branches[$id]->status ?? null], $ids)),
            'branches_label' => $ids ? implode(', ', array_map(fn ($id) => $branches[$id]->code ?? "#{$id}", $ids)) : 'All branches',
            'frequency' => $s->frequency,
            'weekday' => $s->weekday,
            'send_time' => $s->send_time,
            'status' => $s->status,
            'paused_reason' => $s->paused_reason,
            'paused_reason_text' => $s->paused_reason ? (self::PAUSED_REASONS[$s->paused_reason] ?? $s->paused_reason) : null,
            'owner' => $name($s->owner_id),
            'created_by' => $name($s->created_by),
            'created_at' => $s->created_at?->toIso8601String(),
            'updated_by' => $name($s->updated_by),
            'updated_at' => $s->updated_at?->toIso8601String(),
            'archived_at' => $s->archived_at?->toIso8601String(),
            'archived_by' => $name($s->archived_by),
            'archive_reason' => $s->archive_reason,
            'next_run_at' => $s->status === ReportSchedule::ACTIVE ? $s->next_run_at?->toIso8601ZuluString() : null,
            'next_period' => $next ? ['from' => $next[0], 'to' => $next[1]] : null,
            'consecutive_failures' => $s->consecutive_failures,
            'recipients' => $recipients,
            'cannot_receive' => count(array_filter($recipients, fn ($r) => ! $r['eligible'])),
            'last_run' => $last ? ['id' => $last->id, 'planned_at' => $last->planned_at?->toIso8601ZuluString(), 'status' => $last->status]
                + ($pre['counts'] ?? $this->counts($last)) : null,
        ];
    }

    /** "Type: Expense" (I&E), "Types: Top-up, Fund return" (Transfers), else "". */
    public static function paramsLabel(string $report, array $params): string
    {
        if ($report === 'income-expense') {
            return 'Type: '.ucfirst((string) ($params['type'] ?? 'all'));
        }
        if ($report === 'transfers') {
            $types = (array) ($params['types'] ?? []);

            return $types ? 'Types: '.implode(', ', array_map(fn ($t) => ReportService::MOVEMENT_LABELS[$t] ?? $t, $types)) : 'Types: All';
        }

        return '';
    }

    /** @return array{sent: int, skipped: int, failed: int, queued: int} `queued` includes deliveries being sent */
    public function counts(ReportScheduleRun $run): array
    {
        return $this->countsFor([$run->id])[$run->id];
    }

    /**
     * @param  string[]  $runIds
     * @return array<string, array{sent: int, skipped: int, failed: int, queued: int}>
     */
    public function countsFor(array $runIds): array
    {
        $out = [];
        foreach ($runIds as $id) {
            $out[$id] = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'queued' => 0];
        }
        if (! $runIds) {
            return $out;
        }
        $rows = ReportScheduleDelivery::whereIn('run_id', $runIds)->selectRaw('run_id, status, COUNT(*) as n')->groupBy('run_id', 'status')->get();
        foreach ($rows as $r) {
            $key = $r->status === ReportScheduleDelivery::SENDING ? 'queued' : $r->status;
            if (isset($out[$r->run_id][$key])) {
                $out[$r->run_id][$key] += (int) $r->n;
            }
        }

        return $out;
    }

    /** The `run` resource: no file path and no sha256. */
    public function presentRun(ReportScheduleRun $run): array
    {
        $deliveries = ReportScheduleDelivery::with('user:id,name')->where('run_id', $run->id)->orderBy('id')->get();
        $code = $run->branch_id ? Branch::whereKey($run->branch_id)->value('code') : null;

        return [
            'id' => $run->id,
            'planned_at' => $run->planned_at?->toIso8601ZuluString(),
            'period_from' => $run->periodFrom(),
            'period_to' => $run->periodTo(),
            'status' => $run->status,
            'failure_code' => $run->failure_code,
            'error' => $run->error,
            'missed_count' => $run->missed_count,
            'rows' => $run->rows,
            'attached' => (bool) $run->attached,
            'size_bytes' => $run->size_bytes,
            'data_as_at' => $run->data_as_at?->toIso8601ZuluString(),
            'started_at' => $run->started_at?->toIso8601ZuluString(),
            'finished_at' => $run->finished_at?->toIso8601ZuluString(),
            'attempts' => $run->attempts,
        ] + $this->counts($run) + [
            'deliveries' => $deliveries->map(fn (ReportScheduleDelivery $d) => [
                'user' => ['id' => $d->user_id, 'name' => $d->user?->name],
                'email' => $d->email,
                'status' => $d->status,
                'reason' => $d->reason,
                'reason_text' => self::reasonText($d->reason, $code),
                'sent_at' => $d->sent_at?->toIso8601ZuluString(),
                'failed_at' => $d->failed_at?->toIso8601ZuluString(),
            ])->all(),
        ];
    }
}
