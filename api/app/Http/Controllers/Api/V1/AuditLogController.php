<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\ReportWorkbook;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Branch;
use App\Models\Category;
use App\Models\PeriodLock;
use App\Models\RecurringTemplate;
use App\Models\ReportSchedule;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Models\YearEndClose;
use App\Services\ChartOfAccounts;
use App\Services\ReportExporter;
use App\Services\ReportService;
use App\Services\TransferApprovals;
use App\Support\BranchContext;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

/**
 * AG-090 audit log: read-only list, actors and Excel export (controls spec S1, R9-R12 + accounting review 3, 11, 12).
 * audit_logs has no BranchScoped trait, so every query here goes through scoped(), which applies the branch scope itself.
 */
class AuditLogController extends Controller
{
    public const TZ = 'Asia/Colombo';

    public const PER_PAGE = 50;

    public const MAX_DAYS = 366;

    public const MASK = '••••';

    /** Values of the `approval` filter: an audit flag `approval = <value>`, or a boolean key `<value> = true`. */
    public const APPROVAL_FILTERS = [TransferApprovals::NOT_REQUIRED_SUPER_ADMIN, 'approver_onboarded_requester', 'after_approval',
        // AG-066 R6 / review SHOULD 5: "Duplicate template use confirmed" (transaction.expense and expense.approval_requested rows).
        'duplicate_confirmed'];

    /** Keys that match the mask pattern but hold no secret (password reset spec R12: a flag and a count), shown only
     * when the value really is a bool or an int (reviewer N7). */
    public const NOT_SECRET = ['first_password', 'tokens_revoked'];

    /** Event groups of the filter: [prefixes, exact events]. */
    public const GROUPS = [
        // AG-052 R12: receipt events are in the Transactions group.
        // AG-051 R12: approval events too.
        // AG-064 R12: expense approval events too.
        'transactions' => [['transaction.', 'transfer.receipt_', 'transfer.approv', 'expense.approv'], ['transfer.rejected', 'expense.rejected']],
        'voids' => [[], ['transaction.voided', 'transaction.reversed']],
        'users' => [['user.'], []],
        'roles' => [['role.'], []],
        'accounts' => [['account.'], []],
        'branches' => [['branch.'], []],
        'periods' => [['period.'], []],
        // AG-087 R11: background exports are in the Reports group.
        // AG-088 R13: scheduled email reports too.
        'reports' => [['export.', 'report_schedule.'], ['report.exported', 'audit.exported']],
        'logins' => [['auth.'], []],
        'attachments' => [['attachment.'], []],
        // AG-092 R12
        'settings' => [['settings.'], []],
        'year_end' => [['year.'], ['transaction.year_end_close', 'transaction.year_end_reopened']],
        // Categories spec R8
        'categories' => [['category.'], []],
        // AG-093 R9
        'backups' => [['backup.'], []],
        // AG-033 S5
        'bank_reconciliation' => [['reconciliation.'], []],
        // AG-066 S4
        'expense_templates' => [['expense_template.'], []],
    ];

    public const LABELS = [
        'transaction.updated' => 'Entry edited',
        'transaction.reversed' => 'Entry reversed',
        'transaction.reversal' => 'Reversal recorded',
        'transaction.income' => 'Income recorded',
        'transaction.expense' => 'Expense recorded',
        'transaction.capital' => 'Capital recorded',
        'transaction.opening_allocation' => 'Opening allocation recorded',
        'transaction.fund_allocation' => 'Top-up recorded',
        'transaction.fund_return' => 'Fund return recorded',
        'transaction.branch_transfer' => 'Branch transfer recorded',
        'transaction.internal_transfer' => 'Internal transfer recorded',
        'transfer.receipt_received' => 'Receipt confirmed',
        'transfer.receipt_disputed' => 'Discrepancy reported',
        'transfer.receipt_cancelled' => 'Receipt cancelled (reversed)',
        'transfer.approval_requested' => 'Transfer sent for approval',
        'transfer.approved' => 'Transfer approved',
        'transfer.rejected' => 'Transfer rejected',
        'transfer.approval_cancelled' => 'Transfer request cancelled',
        'transfer.approval_expired' => 'Transfer request expired',
        'expense.approval_requested' => 'Expense sent for approval',
        'expense.approved' => 'Expense approved',
        'expense.rejected' => 'Expense rejected',
        'expense.approval_cancelled' => 'Expense request cancelled',
        'expense.approval_expired' => 'Expense request expired',
        'period.locked' => 'Period locked',
        'period.unlocked' => 'Period unlocked',
        'report.exported' => 'Report exported',
        'audit.exported' => 'Audit log exported',
        'export.requested' => 'Export requested',
        'export.ready' => 'Export ready',
        'export.failed' => 'Export failed',
        'export.downloaded' => 'Export downloaded',
        'export.expired' => 'Export expired',
        'export.download_denied' => 'Export download refused',
        // AG-088 R13
        'report_schedule.created' => 'Report schedule created',
        'report_schedule.updated' => 'Report schedule changed',
        'report_schedule.paused' => 'Report schedule paused',
        'report_schedule.resumed' => 'Report schedule resumed',
        'report_schedule.archived' => 'Report schedule archived',
        'report_schedule.restored' => 'Report schedule restored',
        'report_schedule.owner_changed' => 'Report schedule owner changed',
        'report_schedule.run_built' => 'Scheduled report built',
        'report_schedule.run_failed' => 'Scheduled report failed',
        'report_schedule.run_not_delivered' => 'Scheduled report not delivered',
        'report_schedule.run_missed' => 'Scheduled report missed',
        'report_schedule.delivered' => 'Scheduled report emailed',
        'report_schedule.delivery_skipped' => 'Scheduled report not sent',
        'report_schedule.delivery_failed' => 'Scheduled report email failed',
        'report_schedule.files_removed' => 'Scheduled report files removed',
        'auth.login' => 'Login',
        'auth.logout' => 'Logout',
        'auth.locked_out' => 'Login locked',
        'auth.password_reset_requested' => 'Password reset requested',
        'auth.password_reset_refused' => 'Password reset refused',
        'auth.password_reset' => 'Password reset',
        // AG-005 R11 (the "Logins" group covers every auth. event).
        'auth.two_factor_setup_started' => '2FA setup started',
        'auth.two_factor_enabled' => '2FA turned on',
        'auth.two_factor_moved' => '2FA moved to a new phone',
        'auth.two_factor_recovery_regenerated' => 'Recovery codes replaced',
        'auth.two_factor_recovery_used' => 'Recovery code used',
        'auth.two_factor_disabled' => '2FA turned off',
        'auth.two_factor_reset' => '2FA reset',
        'auth.two_factor_locked' => '2FA locked',
        'ledger.legacy_voids_identified' => 'Legacy voids identified',
        'attachment.added' => 'Attachment added',
        'attachment.removed' => 'Attachment removed',
        'attachment.downloaded' => 'Attachment downloaded',
        'attachment.missing' => 'Attachment file missing',
        'attachment.integrity_failed' => 'Attachment file changed',
        'settings.updated' => 'Settings changed',
        'settings.logo_uploaded' => 'Logo uploaded',
        'settings.logo_removed' => 'Logo removed',
        'year.closed' => 'Fiscal year closed',
        'year.reopened' => 'Fiscal year reopened',
        'transaction.year_end_close' => 'Year-end close recorded',
        'transaction.year_end_reopened' => 'Year-end close cancelled (year reopened)',
        'category.created' => 'Category added',
        'category.updated' => 'Category changed',
        'category.promoted' => 'Category made global',
        'backup.created' => 'Backup created',
        'backup.failed' => 'Backup failed',
        'backup.offsite_failed' => 'Backup not copied off-server',
        'backup.pruned' => 'Backup pruned',
        'backup.verified' => 'Restore test passed',
        'backup.verify_failed' => 'Restore test failed',
        'backup.restored' => 'Backup restored',
        'backup.control_failed' => 'Backup: books not balanced',
        'backup.continuity_failed' => 'Database rolled back without a recorded restore',
        'backup.year_end' => 'Year-end snapshot taken',
        // AG-033 R10
        'reconciliation.saved' => 'Bank lines reconciled',
        'reconciliation.completed' => 'Bank statement completed',
        'reconciliation.undone' => 'Bank statement undone',
        // AG-066 S4
        'expense_template.created' => 'Expense template created',
        'expense_template.updated' => 'Expense template changed',
        'expense_template.archived' => 'Expense template archived',
        'expense_template.restored' => 'Expense template restored',
    ];

    public function index(Request $request)
    {
        [$q] = $this->scoped($request);
        $page = $q->with(['user:id,name,status,is_super_admin', 'branch:id,code'])->paginate(self::PER_PAGE);

        return [
            'data' => $this->present($page->getCollection()),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
    }

    /** Users who authored rows in the caller's scope (R9), with the R10 tag. */
    public function actors(Request $request)
    {
        $q = $this->branchScope(AuditLog::query(), $request, []);
        $pairs = $q->whereNotNull('user_id')->select('user_id', 'branch_id')->distinct()->toBase()->get();
        $members = $this->memberships($pairs->pluck('user_id')->unique()->all());

        return User::whereIn('id', $pairs->pluck('user_id')->unique())->orderBy('name')->get(['id', 'name', 'status', 'is_super_admin'])
            ->map(function (User $u) use ($pairs, $members) {
                $branches = $pairs->where('user_id', $u->id)->pluck('branch_id')->filter();

                return ['id' => $u->id, 'name' => $u->name,
                    'tag' => $this->tag($u, $branches->first(fn ($b) => ! isset($members[$u->id.':'.$b])), $members)];
            })->values();
    }

    /** Excel of exactly the list's rows (finding 3: the branch scope is re-applied here, whatever is sent). */
    public function export(Request $request)
    {
        [$q, $f] = $this->scoped($request);
        $total = (clone $q)->count();
        if ($total > ReportService::EXPORT_CAP) {
            return response()->json(['message' => 'Too many rows ('.number_format($total).'). Narrow the filters.'], 422);
        }
        $rows = $this->present($q->with(['user:id,name,status,is_super_admin', 'branch:id,code'])->get());

        $user = $request->user();
        $lines = [
            app(Settings::class)->companyName(),
            'Audit log',
            'Branches: '.$this->branchLabel($f),
            'Period: '.$f['from'].' to '.$f['to'].' (Asia/Colombo days)',
            'Filters: '.$this->filterLabel($f),
            'Rows: '.$total,
            'Printed by '.$user->name.' at '.now()->setTimezone(self::TZ)->format('Y-m-d H:i').' (Asia/Colombo)',
        ];
        $columns = [['Date time', 'text'], ['Branch', 'text'], ['User', 'text'], ['Event', 'text'], ['Subject', 'text'], ['Summary', 'text'],
            ['Self', 'text'], ['IP', 'text'], ['Event key', 'text'], ['Old values', 'text'], ['New values', 'text']];
        $sheetRows = array_map(fn (array $r) => ['bold' => false, 'cells' => [
            $r['created_at_local'],
            $r['branch']['code'] ?? '—',
            $r['system'] ? 'System' : ($r['user'] ? $r['user']['name'].($r['user']['tag'] ? " ({$r['user']['tag']})" : '') : '—'),
            $r['event_label'],
            $r['subject'],
            $r['summary'],
            $r['self'] ? 'Self' : '',
            $r['ip'],
            $r['event'],
            $r['old_values'] === null ? '' : json_encode($r['old_values'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $r['new_values'] === null ? '' : json_encode($r['new_values'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]], $rows);

        $file = "audit-log_{$f['from']}_{$f['to']}.xlsx";
        $response = Excel::download(new ReportWorkbook($lines, [['title' => 'Audit log', 'columns' => $columns, 'rows' => $sheetRows]]), $file, ExcelWriter::XLSX);
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$file.'"');

        $ctx = app(BranchContext::class);
        $branchId = $ctx->isScoped() ? $ctx->branchId
            : (count($f['branches']) === 1 && $f['branches'][0] !== 'none' ? (int) $f['branches'][0] : null);
        AuditLog::record('audit.exported', null, null, ['filters' => array_filter($f, fn ($v) => $v !== null && $v !== []), 'rows' => $total], $branchId);

        return $response;
    }

    // ------------------------------------------------------------------ query

    /** @return array{0: Builder, 1: array} validated filters applied on top of the caller's branch scope */
    private function scoped(Request $request): array
    {
        $f = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'event_group' => ['nullable', Rule::in(array_keys(self::GROUPS))],
            'event' => 'nullable|string|max:50',
            'user_id' => 'nullable|integer',
            'self' => 'nullable|boolean',
            'ref_no' => 'nullable|string|max:40',
            // AG-051 finding 6: Super Admin movements above the threshold that were not held.
            // AG-064: approvals by the user who brought the requester in (R4b) and changes after approval (R21).
            'approval' => ['nullable', Rule::in(self::APPROVAL_FILTERS)],
            'branches' => 'nullable|array',
            'branches.*' => 'distinct',
            'page' => 'nullable|integer|min:1',
        ]);
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $f['from'], self::TZ);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $f['to'], self::TZ);
        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'The date range may not be longer than '.self::MAX_DAYS.' days.']);
        }
        $f['branches'] = array_map(fn ($b) => is_scalar($b) ? (string) $b : '', array_values($f['branches'] ?? []));
        $f += ['event_group' => null, 'event' => null, 'user_id' => null, 'self' => null, 'ref_no' => null, 'approval' => null];

        $q = $this->branchScope(AuditLog::query(), $request, $f['branches']);
        foreach ($f['branches'] as $b) {
            if ($b !== 'none' && ! (ctype_digit($b) && Branch::whereKey((int) $b)->exists())) {
                throw ValidationException::withMessages(['branches' => 'Unknown branch.']);
            }
        }

        // R12: Asia/Colombo days as a UTC [from 00:00, to+1 00:00) range with plain comparisons (SQLite and MySQL).
        $utc = fn (CarbonImmutable $d) => $d->setTimezone('UTC')->format('Y-m-d H:i:s');
        $q->where('created_at', '>=', $utc($from))->where('created_at', '<', $utc($to->addDay()));

        if ($f['event_group']) {
            [$prefixes, $events] = self::GROUPS[$f['event_group']];
            $q->where(function ($w) use ($prefixes, $events) {
                foreach ($prefixes as $p) {
                    $w->orWhere('event', 'like', $p.'%');
                }
                if ($events) {
                    $w->orWhereIn('event', $events);
                }
            });
        }
        $q->when($f['event'], fn ($q, $v) => $q->where('event', $v))
            ->when($f['user_id'], fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->boolean('self'), fn ($q) => $q->where('new_values->self', true))
            ->when($f['approval'], fn ($q, $v) => $v === TransferApprovals::NOT_REQUIRED_SUPER_ADMIN
                ? $q->where('new_values->approval', $v) : $q->where("new_values->{$v}", true));
        if ($f['ref_no']) {
            $ids = Transaction::withoutGlobalScopes()->where('ref_no', $f['ref_no'])->pluck('id');
            $q->where('auditable_type', Transaction::class)->whereIn('auditable_id', $ids);
        }

        return [$q->orderByDesc('created_at')->orderByDesc('id'), $f];
    }

    /**
     * R9: Super Admin in All mode sees every row (branches[] narrows, "none" = no branch); everyone else, and a
     * Super Admin in branch mode, sees the active branch only. branches[] outside Super Admin All mode is a 403.
     */
    private function branchScope(Builder $q, Request $request, array $branches): Builder
    {
        $ctx = app(BranchContext::class);
        $allMode = ! $ctx->isScoped() && $request->user()->is_super_admin;
        if (! $allMode) {
            abort_if($branches !== [] || $request->has('branches'), 403, 'The branch filter is only available to a Super Admin in All branches mode.');
            abort_unless($ctx->isScoped(), 403, 'Select a branch.');

            return $q->where('branch_id', $ctx->branchId);
        }
        if ($branches) {
            $ids = array_map('intval', array_filter($branches, fn ($b) => $b !== 'none'));
            $q->where(function ($w) use ($ids, $branches) {
                if ($ids) {
                    $w->whereIn('branch_id', $ids);
                }
                if (in_array('none', $branches, true)) {
                    $w->orWhereNull('branch_id');
                }
            });
        }

        return $q;
    }

    // ------------------------------------------------------------------ presentation

    /** @param  Collection<int, AuditLog>  $rows */
    private function present(Collection $rows): array
    {
        $members = $this->memberships($rows->pluck('user_id')->filter()->unique()->all());
        $subjects = $this->subjects($rows);

        return $rows->map(function (AuditLog $a) use ($members, $subjects) {
            $old = self::mask($a->old_values);
            $new = self::mask($a->new_values);
            $txn = $a->auditable_type === Transaction::class ? ($subjects['txn'][$a->auditable_id] ?? null) : null;

            return [
                'id' => $a->id,
                'created_at' => $a->created_at?->toIso8601String(),
                'created_at_local' => $a->created_at?->copy()->setTimezone(self::TZ)->format('Y-m-d H:i:s'),
                'branch' => $a->branch ? ['id' => $a->branch->id, 'code' => $a->branch->code] : null,
                'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name, 'tag' => $this->tag($a->user, $a->branch_id, $members)] : null,
                // AG-087 finding 7: a system event (ready, failed by the job or prune, expired) shows "System" as its user.
                'system' => ($a->new_values['by_system'] ?? false) === true,
                'event' => $a->event,
                'event_label' => self::label($a->event, $txn?->type ?? ($a->new_values['type'] ?? null)),
                'subject' => $this->subject($a, $subjects),
                'auditable_type' => $a->auditable_type ? class_basename($a->auditable_type) : null,
                'auditable_id' => $a->auditable_id,
                'old_values' => $old,
                'new_values' => $new,
                'summary' => str_starts_with($a->event, 'transfer.receipt_') ? self::receiptSummary($old, $new) : self::summary($old, $new),
                'self' => ($a->new_values['self'] ?? false) === true,
                'ip' => $a->ip,
                'user_agent' => $a->user_agent,
            ];
        })->values()->all();
    }

    public static function label(string $event, ?string $txnType = null): string
    {
        if ($event === 'transaction.voided') {
            return ($txnType ? ReportService::typeLabel($txnType) : 'Entry').' voided';
        }

        return self::LABELS[$event] ?? Str::ucfirst(str_replace(['.', '_'], ' ', $event));
    }

    /** R10 / finding 3 of users-and-roles: "inactive", else "no longer in this branch", never on rows without a branch. */
    private function tag(User $u, ?int $branchId, array $members): ?string
    {
        if ($u->status !== 'active') {
            return 'inactive';
        }
        if ($branchId !== null && ! $u->is_super_admin && ! isset($members[$u->id.':'.$branchId])) {
            return 'no longer in this branch';
        }

        return null;
    }

    /** @return array<string, true> keyed "user_id:branch_id" */
    private function memberships(array $userIds): array
    {
        return DB::table('branch_user')->whereIn('user_id', $userIds)->get(['user_id', 'branch_id'])
            ->mapWithKeys(fn ($r) => [$r->user_id.':'.$r->branch_id => true])->all();
    }

    private function subjects(Collection $rows): array
    {
        $ids = fn (string $type) => $rows->where('auditable_type', $type)->pluck('auditable_id')->unique()->all();

        return [
            'txn' => Transaction::withoutGlobalScopes()->whereIn('id', $ids(Transaction::class))->get(['id', 'ref_no', 'type'])->keyBy('id'),
            'user' => User::whereIn('id', $ids(User::class))->pluck('name', 'id'),
            'role' => Role::whereIn('id', $ids(Role::class))->pluck('name', 'id'),
            'account' => Account::withoutGlobalScopes()->whereIn('id', $ids(Account::class))->pluck('name', 'id'),
            'branch' => Branch::whereIn('id', $ids(Branch::class))->pluck('code', 'id'),
            'category' => Category::with('parent:id,name')->whereIn('id', $ids(Category::class))->get()->keyBy('id'),
            'lock' => PeriodLock::with('branch:id,code')->whereIn('id', $ids(PeriodLock::class))->get()->keyBy('id'),
            // AG-066: the rows are already limited to the caller's branches (branchScope), so the names are read unscoped.
            'template' => RecurringTemplate::withoutGlobalScopes()->whereIn('id', $ids(RecurringTemplate::class))->pluck('name', 'id'),
            // AG-088: the rows are already limited to the caller's branches; the schedule's current name.
            'schedule' => ReportSchedule::whereIn('id', $ids(ReportSchedule::class))->pluck('name', 'id'),
        ];
    }

    private function subject(AuditLog $a, array $s): string
    {
        $id = $a->auditable_id;

        return (string) match ($a->auditable_type) {
            Transaction::class => $s['txn'][$id]->ref_no ?? ($a->new_values['ref_no'] ?? "#{$id}"),
            User::class => $s['user'][$id] ?? "#{$id}",
            Role::class => $s['role'][$id] ?? ($a->new_values['name'] ?? "#{$id}"),
            Account::class => $s['account'][$id] ?? "#{$id}",
            Branch::class => $s['branch'][$id] ?? "#{$id}",
            // Current name with its code; the name at the time is in old/new values (R10).
            Category::class => isset($s['category'][$id])
                ? ChartOfAccounts::categoryAccountName($s['category'][$id]).' ('.$s['category'][$id]->ledger_code.')'
                : "#{$id}",
            PeriodLock::class => isset($s['lock'][$id])
                ? PeriodLock::label($s['lock'][$id]->period).' ('.($s['lock'][$id]->branch->code ?? 'all branches').')'
                : "#{$id}",
            Backup::class => (string) ($a->new_values['filename'] ?? "#{$id}"),
            RecurringTemplate::class => $s['template'][$id] ?? ($a->new_values['name'] ?? "#{$id}"),
            ReportSchedule::class => $s['schedule'][$id] ?? ($a->new_values['name'] ?? "#{$id}"),
            YearEndClose::class => app(Settings::class)->fiscalYearStarting((int) ($a->new_values['fiscal_year'] ?? $a->old_values['fiscal_year'] ?? YearEndClose::find($id)?->fiscal_year ?? 0))->label(),
            default => match ($a->event) {
                // AG-065 R14: 'transactions' is not a ReportService name (that would open reports/transactions/export).
                'report.exported', 'export.requested', 'export.ready', 'export.failed', 'export.downloaded', 'export.expired', 'export.download_denied' => (ReportExporter::TITLES + ['transactions' => 'Transactions'])[$a->new_values['name'] ?? ''] ?? '',
                'audit.exported' => 'Audit log',
                'report_schedule.files_removed' => ($a->new_values['count'] ?? 0).' scheduled report file(s)',
                'settings.updated' => 'Settings',
                'settings.logo_uploaded', 'settings.logo_removed' => 'Logo',
                'ledger.legacy_voids_identified' => ($a->new_values['count'] ?? 0).' legacy voids',
                'backup.verified', 'backup.verify_failed', 'backup.restored' => (string) ($a->new_values['filename'] ?? ''),
                default => '',
            },
        };
    }

    /** R11 / finding 12: any key matching password|token|secret, at any depth, is replaced on the server. */
    public static function mask(mixed $values): mixed
    {
        if (! is_array($values)) {
            return $values;
        }
        foreach ($values as $k => $v) {
            $plain = in_array($k, self::NOT_SECRET, true) && (is_bool($v) || is_int($v));
            $secret = is_string($k) && ! $plain && preg_match('/password|token|secret/i', $k);
            $values[$k] = $secret ? self::MASK : self::mask($v);
        }

        return $values;
    }

    /** First two changed fields as "field: old -> new" (values already masked). */
    public static function summary(?array $old, ?array $new): string
    {
        $old ??= [];
        $new ??= [];
        $parts = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $k) {
            if ($k === 'self' || ($old[$k] ?? null) === ($new[$k] ?? null)) {
                continue;
            }
            $parts[] = $k.': '.self::short($old[$k] ?? null).' -> '.self::short($new[$k] ?? null);
            if (count($parts) === 2) {
                break;
            }
        }

        return implode('; ', $parts);
    }

    /**
     * AG-052 (QA O3): a receipt row always shows the state change, then the business date and the note, so a back-dated
     * confirmation is visible in the list.
     */
    public static function receiptSummary(?array $old, ?array $new): string
    {
        $parts = ['receipt_status: '.self::short($old['receipt_status'] ?? null).' -> '.self::short($new['receipt_status'] ?? null)];
        foreach (['received_on', 'note', 'reversal_ref'] as $k) {
            if (($new[$k] ?? null) !== null && $new[$k] !== '') {
                $parts[] = $k.': '.self::short($new[$k]);
            }
        }

        return implode('; ', $parts);
    }

    private static function short(mixed $v): string
    {
        $s = match (true) {
            $v === null => '—',
            is_bool($v) => $v ? 'yes' : 'no',
            is_array($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $v,
        };

        return Str::limit($s, 60);
    }

    private function branchLabel(array $f): string
    {
        $ctx = app(BranchContext::class);
        if ($ctx->isScoped()) {
            $b = Branch::find($ctx->branchId);

            return "{$b->code} {$b->name}";
        }
        if (! $f['branches']) {
            return 'All branches (incl. no branch)';
        }
        $codes = Branch::whereIn('id', array_map('intval', array_filter($f['branches'], fn ($b) => $b !== 'none')))->orderBy('code')->get()
            ->map(fn ($b) => "{$b->code} {$b->name}")->all();
        if (in_array('none', $f['branches'], true)) {
            $codes[] = 'No branch (global)';
        }

        return implode(', ', $codes);
    }

    private function filterLabel(array $f): string
    {
        $parts = [];
        if ($f['event_group']) {
            $parts[] = 'Event group: '.$f['event_group'];
        }
        if ($f['event']) {
            $parts[] = 'Event: '.$f['event'];
        }
        if ($f['user_id']) {
            $parts[] = 'User: '.(User::find($f['user_id'])->name ?? "#{$f['user_id']}");
        }
        if ($f['self']) {
            $parts[] = 'Self only';
        }
        if ($f['ref_no']) {
            $parts[] = 'Ref no: '.$f['ref_no'];
        }

        return $parts ? implode('; ', $parts) : 'none';
    }
}
