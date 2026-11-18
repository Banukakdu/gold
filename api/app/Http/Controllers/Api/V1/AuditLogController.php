<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\ReportWorkbook;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\PeriodLock;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ReportExporter;
use App\Services\ReportService;
use App\Support\BranchContext;
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

    /** Event groups of the filter: [prefixes, exact events]. */
    public const GROUPS = [
        'transactions' => [['transaction.'], []],
        'voids' => [[], ['transaction.voided', 'transaction.reversed']],
        'users' => [['user.'], []],
        'roles' => [['role.'], []],
        'accounts' => [['account.'], []],
        'branches' => [['branch.'], []],
        'periods' => [['period.'], []],
        'reports' => [[], ['report.exported', 'audit.exported']],
        'logins' => [[], ['auth.login']],
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
        'period.locked' => 'Period locked',
        'period.unlocked' => 'Period unlocked',
        'report.exported' => 'Report exported',
        'audit.exported' => 'Audit log exported',
        'auth.login' => 'Login',
        'ledger.legacy_voids_identified' => 'Legacy voids identified',
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
            (string) Setting::get('company_name', config('app.name')),
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
            $r['user'] ? $r['user']['name'].($r['user']['tag'] ? " ({$r['user']['tag']})" : '') : '—',
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
        $f += ['event_group' => null, 'event' => null, 'user_id' => null, 'self' => null, 'ref_no' => null];

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
            ->when($request->boolean('self'), fn ($q) => $q->where('new_values->self', true));
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
                'event' => $a->event,
                'event_label' => self::label($a->event, $txn?->type ?? ($a->new_values['type'] ?? null)),
                'subject' => $this->subject($a, $subjects),
                'auditable_type' => $a->auditable_type ? class_basename($a->auditable_type) : null,
                'auditable_id' => $a->auditable_id,
                'old_values' => $old,
                'new_values' => $new,
                'summary' => self::summary($old, $new),
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
            'lock' => PeriodLock::with('branch:id,code')->whereIn('id', $ids(PeriodLock::class))->get()->keyBy('id'),
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
            PeriodLock::class => isset($s['lock'][$id])
                ? PeriodLock::label($s['lock'][$id]->period).' ('.($s['lock'][$id]->branch->code ?? 'all branches').')'
                : "#{$id}",
            default => match ($a->event) {
                'report.exported' => ReportExporter::TITLES[$a->new_values['name'] ?? ''] ?? '',
                'audit.exported' => 'Audit log',
                'ledger.legacy_voids_identified' => ($a->new_values['count'] ?? 0).' legacy voids',
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
            $values[$k] = is_string($k) && preg_match('/password|token|secret/i', $k) ? self::MASK : self::mask($v);
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
