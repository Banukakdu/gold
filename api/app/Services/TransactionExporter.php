<?php

namespace App\Services;

use App\Exports\ReportWorkbook;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use App\Support\Settings;
use App\Support\TransactionFilters;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/** AG-065 R14: the filtered transaction list as one xlsx sheet, built with ReportWorkbook (typed cells, no formulas). */
class TransactionExporter
{
    public const SORT_LABELS = [
        'date:desc' => 'Date, newest first',
        'date:asc' => 'Date, oldest first',
        'amount:desc' => 'Amount, highest first',
        'amount:asc' => 'Amount, lowest first',
    ];

    public const FILES_LABELS = ['with' => 'Has files', 'none' => 'No files', 'removed' => 'Has removed files'];

    public const YEAR_END_NOTE = 'Year-end close amounts are not money moved and are shown as —.';

    public function __construct(private TransactionSearch $search) {}

    public function download(TransactionFilters $p, User $user): Response
    {
        $query = $this->search->query($p);
        $count = (clone $query)->count();
        if ($count > ReportService::EXPORT_CAP) {
            // Nothing is written and nothing is audited.
            abort(response()->json(['message' => 'This export has '.number_format($count).' rows; the limit is '
                .number_format(ReportService::EXPORT_CAP).'. Narrow the filters.'], 422));
        }
        $rows = $query->with(TransactionController::LIST_RELATIONS)
            ->withCount(['attachments as attachments_count' => fn ($q) => $q->whereNull('removed_at')])
            ->get();

        $lines = $this->header($p, $rows, $user);
        $file = 'transactions_'.($p->get('from') ?? 'all').'_'.($p->get('to') ?? 'all').'.xlsx';
        $sheet = ['title' => 'Transactions', 'columns' => self::COLUMNS, 'rows' => $this->rows($p, $rows)];

        $response = Excel::download(new ReportWorkbook($lines, [$sheet]), $file, ExcelWriter::XLSX);
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$file.'"');
        AuditLog::record('report.exported', null, null,
            ['name' => 'transactions', 'format' => 'xlsx', 'params' => $p->auditParams(), 'rows' => $rows->count()], $p->auditBranchId());

        return $response;
    }

    public const COLUMNS = [
        ['Date', 'date'], ['Created at', 'text'], ['Ref no', 'text'], ['Branch', 'text'], ['Type', 'text'], ['Direction', 'text'],
        ['Category', 'text'], ['Sub-category', 'text'], ['Account', 'text'], ['From', 'text'], ['To', 'text'], ['Payee / payer', 'text'],
        ['Payment method', 'text'], ['Reference', 'text'], ['Description', 'text'], ['Amount', 'amount'], ['Status', 'text'],
        ['Reversal of', 'text'], ['Reversed by', 'text'], ['Voided on', 'date'], ['Voided by', 'text'], ['Void reason', 'text'],
        ['Created by', 'text'], ['Files', 'text'],
    ];

    /** @param  Collection<int, Transaction>  $rows */
    private function rows(TransactionFilters $p, Collection $rows): array
    {
        $settings = app(Settings::class);
        $voids = $this->voidEvidence($rows);
        $members = $this->memberships($rows->pluck('created_by')->unique()->all());

        return $rows->map(function (Transaction $t) use ($p, $settings, $voids, $members) {
            $closing = $t->type === Transaction::YEAR_END_CLOSE;
            [$category, $sub] = $this->categoryCells($t->category);
            $transfer = $t->from_account_id !== null || $t->to_account_id !== null;
            $void = $voids[$t->id] ?? null;
            $created = $t->created_at?->copy()->setTimezone('Asia/Colombo');
            $u = $t->creator;
            $tag = match (true) {
                $u === null => null,
                $u->status !== 'active' => 'inactive',
                ! $u->is_super_admin && ! isset($members[$u->id.':'.$t->branch_id]) => 'no longer in this branch',
                default => null,
            };

            return ['bold' => false, 'cells' => [
                $t->txn_date->format('Y-m-d'),
                $created ? $settings->formatDate($created->format('Y-m-d')).' '.$created->format('H:i') : '',
                $t->ref_no,
                $t->branch?->code,
                ReportService::typeLabel($t->type).($p->isIncoming($t) ? ' (in)' : ''),
                $p->direction($t),
                $category,
                $sub,
                $transfer ? '' : $t->account?->name,
                $transfer ? self::accountLabel($t->fromAccount) : '',
                $transfer ? self::accountLabel($t->toAccount) : '',
                $t->payee_payer,
                $t->payment_method ? ucfirst(str_replace('_', ' ', $t->payment_method)) : '',
                $t->reference,
                $t->description,
                $closing ? '—' : Money::fromCents(Money::toCents($t->amount)),
                self::statusLabel($t),
                $t->reversalOf?->ref_no,
                $t->reversedBy?->ref_no,
                $void['date'] ?? null,
                $void['by'] ?? null,
                $void['reason'] ?? null,
                $u ? $u->name.($tag ? " ({$tag})" : '') : '',
                (string) (int) $t->attachments_count,
            ]];
        })->all();
    }

    /** Screen and Excel status (accounting review findings 2 and 8). */
    public static function statusLabel(Transaction $t): string
    {
        return match (true) {
            $t->type === Transaction::REVERSAL => 'Reversal of '.($t->reversalOf?->ref_no ?? ''),
            $t->status === 'reversed' => trim('Reversed by '.($t->reversedBy?->ref_no ?? '')),
            $t->status === 'void' && $t->type === Transaction::YEAR_END_CLOSE => 'Void (year reopened)',
            $t->status === 'void' => 'Void',
            default => 'Posted',
        };
    }

    private static function accountLabel($account): string
    {
        return $account ? trim(($account->branch?->code ?? '').' – '.$account->name) : '';
    }

    /** [top-level, sub] names, "(Inactive)" appended as in the I&E export (a sub is inactive when it or its parent is). */
    private function categoryCells(?Category $c): array
    {
        if (! $c) {
            return ['', ''];
        }
        $parent = $c->parent;
        if (! $parent) {
            return [$c->name.($c->is_active ? '' : ' (Inactive)'), ''];
        }

        return [$parent->name.($parent->is_active ? '' : ' (Inactive)'), $c->name.($c->is_active && $parent->is_active ? '' : ' (Inactive)')];
    }

    /**
     * Accounting review finding 1: who voided, when and why, from the transaction.voided audit row (a closing entry
     * cancelled by reopening the year: transaction.year_end_reopened). One query per 1,000 exported void rows.
     *
     * @return array<int, array{date: string, by: ?string, reason: ?string}>
     */
    private function voidEvidence(Collection $rows): array
    {
        $ids = $rows->where('status', 'void')->pluck('id')->all();
        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $audits = AuditLog::with('user:id,name')
                ->whereIn('event', ['transaction.voided', 'transaction.year_end_reopened'])
                ->where('auditable_type', Transaction::class)->whereIn('auditable_id', $chunk)
                ->orderBy('id')->get();
            foreach ($audits as $a) {
                $out[(int) $a->auditable_id] ??= [
                    'date' => $a->created_at->copy()->setTimezone('Asia/Colombo')->format('Y-m-d'),
                    'by' => $a->user?->name,
                    'reason' => $a->new_values['reason'] ?? null,
                ];
            }
        }

        return $out;
    }

    /** @return array<string, true> keyed "user_id:branch_id" */
    private function memberships(array $userIds): array
    {
        return DB::table('branch_user')->whereIn('user_id', $userIds)->get(['user_id', 'branch_id'])
            ->mapWithKeys(fn ($r) => [$r->user_id.':'.$r->branch_id => true])->all();
    }

    /** R14 header lines (each a row above the table). */
    private function header(TransactionFilters $p, Collection $rows, User $user): array
    {
        $settings = app(Settings::class);
        $now = now()->setTimezone('Asia/Colombo');
        $from = $p->get('from');
        $to = $p->get('to');
        $period = match (true) {
            $from && $to => $settings->formatDate($from).' to '.$settings->formatDate($to),
            (bool) $from => 'From '.$settings->formatDate($from),
            (bool) $to => 'Up to '.$settings->formatDate($to),
            default => 'All dates',
        };
        $filters = $this->filterLabel($p, $rows);

        return array_values(array_filter([
            $settings->companyName(),
            'Transactions',
            'Branches: '.$this->branchLabel($p),
            'Period: '.$period,
            'Filters: '.($filters ?: 'none'),
            'Sort: '.self::SORT_LABELS[$p->sort.':'.$p->dir],
            'Rows: '.number_format($rows->count()),
            'Amounts in '.$settings->currencyCode(),
            'Printed by '.$user->name.' at '.$settings->formatDate($now->format('Y-m-d')).' '.$now->format('H:i').' (Asia/Colombo)',
            $rows->contains('type', Transaction::YEAR_END_CLOSE) ? self::YEAR_END_NOTE : null,
        ]));
    }

    private function branchLabel(TransactionFilters $p): string
    {
        if ($p->scope === null) {
            return 'All branches';
        }

        return Branch::whereIn('id', $p->scope)->get()->sortBy(fn (Branch $b) => [$b->is_main ? 0 : 1, $b->code])
            ->map(fn (Branch $b) => "{$b->code} {$b->name}")->implode(', ');
    }

    /**
     * "Type: Income, Expense; Status: Void; ..." Account and user names are taken from the exported rows only, so the
     * header never names an account or user outside the scope set (R7, finding 3); otherwise "#<id>".
     */
    private function filterLabel(TransactionFilters $p, Collection $rows): string
    {
        $parts = [];
        $list = fn (string $v) => array_values(array_filter(array_map('trim', explode(',', $v))));
        if ($v = $p->get('type')) {
            $parts[] = 'Type: '.implode(', ', array_map([ReportService::class, 'typeLabel'], $list($v)));
        }
        if ($v = $p->get('status')) {
            $parts[] = 'Status: '.implode(', ', array_map('ucfirst', $list($v)));
        }
        if ($id = $p->get('account_id')) {
            $id = (int) $id;
            $acc = $rows->map(fn (Transaction $t) => collect([$t->account, $t->fromAccount, $t->toAccount])->first(fn ($a) => $a && (int) $a->id === $id))
                ->filter()->first();
            $code = $acc ? Branch::whereKey($acc->branch_id)->value('code') : null;
            $parts[] = 'Account: '.($acc ? trim("{$code} {$acc->name}") : "#{$id}");
        }
        if ($id = $p->get('category_id')) {
            $c = Category::with('parent:id,name')->find((int) $id);
            $label = $c ? ($c->parent ? "{$c->parent->name} › {$c->name}" : $c->name) : "Category #{$id}";
            if ($c && ! $c->parent && $p->get('category_only')) {
                $label .= ' › (no sub-category)';
            }
            $parts[] = 'Category: '.$label;
        }
        if ($id = $p->get('created_by')) {
            $u = $rows->first(fn (Transaction $t) => (int) $t->created_by === (int) $id)?->creator;
            $parts[] = 'Created by: '.($u ? $u->name : "#{$id}");
        }
        $min = $p->get('min');
        $max = $p->get('max');
        if ($min && $max) {
            $parts[] = 'Amount: '.Money::format($min).' to '.Money::format($max);
        } elseif ($min) {
            $parts[] = 'Amount: from '.Money::format($min);
        } elseif ($max) {
            $parts[] = 'Amount: up to '.Money::format($max);
        }
        if (($q = trim((string) $p->get('q'))) !== '') {
            $parts[] = 'Search: "'.$q.'"';
        }
        if ($v = $p->get('files')) {
            $parts[] = 'Files: '.self::FILES_LABELS[$v];
        }

        return implode('; ', $parts);
    }
}
