<?php

namespace App\Services;

use App\Exceptions\ExportFailure;
use App\Exports\ReportWorkbook;
use App\Exports\XlsxStreamWriter;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Money;
use App\Support\ReadSnapshot;
use App\Support\ReportParams;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF / Excel export of the reports (spec section 5, accounting review finding 4/5): synchronous, or built into a file by
 * a background export (AG-087, docs/specs/background-exports.md).
 * The same numbers as the screen: every export is built from ReportService with all rows (not one screen page).
 */
class ReportExporter
{
    public const TITLES = [
        'income-expense' => 'Income & Expense by Category',
        'branch-comparison' => 'Branch Comparison',
        'transfers' => 'Transfers & Allocations',
        'cash-book' => 'Daily Cash Book',
        'trial-balance' => 'Trial Balance',
        'cash-flow' => 'Cash Flow Summary',
    ];

    /** AG-086 columns after Month, in the spec's order (review finding 2), Eliminated last (finding 7). */
    public const CASH_FLOW_COLUMNS = [
        'opening' => 'Opening', 'income' => 'Income received', 'expenses' => 'Expenses paid', 'operating_net' => 'Operating net',
        'capital' => 'Capital introduced', 'funds_received' => 'Funds received', 'transfers_in' => 'Transfers in', 'transfers_out' => 'Transfers out',
        'funding_net' => 'Funding net', 'inflow' => 'Total inflow', 'outflow' => 'Total outflow', 'net' => 'Net change', 'closing' => 'Closing (cumulative)',
        'eliminated' => 'Eliminated',
    ];

    /**
     * The screen notes, plus the negative-amount note when only the Eliminated column (exports only) is negative:
     * the screen shows the note only for figures it shows (review m5).
     */
    public static function cashFlowNotes(array $d): array
    {
        $notes = $d['notes'];
        $negative = collect([...$d['months'], $d['total']])->contains(fn ($m) => Money::cmp($m['eliminated'], 0) < 0);
        if ($negative && ! in_array(ReportService::CASH_FLOW_NEGATIVE_NOTE, $notes, true)) {
            $notes[] = ReportService::CASH_FLOW_NEGATIVE_NOTE;
        }

        return $notes;
    }

    /** Rows per PDF page for lists that carry Brought forward / Carried forward (finding 5), by paper orientation. */
    public const PDF_ROWS_PORTRAIT = 28;

    public const PDF_ROWS_LANDSCAPE = 20;

    public function __construct(private ReportService $reports) {}

    /** Detail rows the export would hold (spec: I&E category lines, comparison branches, movements, cash book lines). */
    public function countRows(string $name, ReportParams $p, ?array $data = null): int
    {
        return match ($name) {
            'income-expense' => $this->reports->incomeExpenseRows($data ?? $this->reports->incomeExpense($p)),
            'branch-comparison' => $p->branches->count(),
            'transfers' => $this->reports->transferRows($p),
            'cash-book' => $this->reports->cashBookRows($p),
            'trial-balance' => $this->reports->trialBalanceRows($data ?? $this->reports->trialBalance($p)),
            // AG-086: one row per month (at most 24, always under the caps).
            'cash-flow' => count(ReportService::monthsBetween($p->from, $p->to)),
        };
    }

    /**
     * The export endpoint (AG-087 R1). Read inside one consistent snapshot (finding 12). An Excel export with more than
     * `exports.sync_rows` rows (at most `exports.max_rows`) is handed to $queue with its row count, except the Cash
     * Flow Summary (at most 24 rows); PDF is never queued and keeps its own cap. `report.exported` is written after
     * the snapshot closes (writes are not possible inside it).
     *
     * @param  (Closure(int): Response)|null  $queue  null = synchronous only (Excel cap = sync_rows)
     */
    public function download(string $name, ReportParams $p, string $format, User $user, ?Closure $queue = null): Response
    {
        $rows = 0;
        $response = ReadSnapshot::run(function () use ($name, $p, $format, $user, $queue, &$rows) {
            // Income & Expense counts lines from the built report: build it once and reuse it.
            $data = $this->prebuild($name, $p);
            $rows = $this->countRows($name, $p, $data);
            ReadSnapshot::hook('counted');
            if ($format === 'xlsx' && $rows > BackgroundExports::syncRows() && $name !== 'cash-flow') {
                if ($rows > BackgroundExports::maxRows() || ! $queue) {
                    $cap = $queue ? BackgroundExports::maxRows() : BackgroundExports::syncRows();
                    abort(response()->json(['message' => 'This export has '.number_format($rows).' rows; the limit is '.number_format($cap).'. Narrow the date range or branches.'], 422));
                }

                return null; // queued below, outside the snapshot
            }
            if ($format === 'pdf' && $rows > ReportService::PDF_EXPORT_CAP) {
                abort(response()->json(['message' => 'This export has '.number_format($rows).' rows; the limit for PDF is '
                    .number_format(ReportService::PDF_EXPORT_CAP).'. Use Excel or narrow the date range or branches.'], 422));
            }
            if ($format === 'pdf') {
                self::raisePdfLimits();
            }

            $data ??= $this->build($name, $p);
            $head = $this->header($name, $p, $data, $user);
            $file = self::fileName($name, $p, $format);

            $response = $format === 'xlsx'
                ? Excel::download(new ReportWorkbook($head['lines'], $this->sheets($name, $data)), $file, ExcelWriter::XLSX)
                : $this->pdf($name, $head, $data, $file);
            $response->headers->set('Content-Disposition', 'attachment; filename="'.$file.'"');

            return $response;
        });
        if ($response === null) {
            return $queue($rows);
        }

        AuditLog::record('report.exported', null, null, ['name' => $name, 'format' => $format, 'params' => $p->toArray(), 'rows' => $rows],
            $p->branches->count() === 1 ? $p->branches->first()->id : null);

        return $response;
    }

    public static function fileName(string $name, ReportParams $p, string $format = 'xlsx'): string
    {
        return "{$name}_{$p->from}_{$p->to}.{$format}";
    }

    /** Finding 5: detail rows held by the built data (compared with the count taken in the snapshot). */
    public function builtRows(string $name, array $d): int
    {
        return match ($name) {
            'income-expense' => $this->reports->incomeExpenseRows($d),
            'branch-comparison' => count($d['rows']),
            'transfers' => count($d['movements']['data']),
            'cash-book' => array_sum(array_map(fn ($a) => array_sum(array_map(fn ($day) => count($day['lines']), $a['days'])), $d['accounts'])),
            'trial-balance' => $this->reports->trialBalanceRows($d),
            'cash-flow' => count($d['months']),
        };
    }

    /** The data the row count needs (I&E and trial balance count lines of the built report), else null. */
    private function prebuild(string $name, ReportParams $p): ?array
    {
        return match ($name) {
            'income-expense' => $this->reports->incomeExpense($p),
            'trial-balance' => $this->reports->trialBalance($p),
            default => null,
        };
    }

    private function build(string $name, ReportParams $p): array
    {
        return match ($name) {
            'branch-comparison' => $this->reports->branchComparison($p),
            'transfers' => $this->reports->transfers($p, all: true),
            'cash-book' => $this->reports->cashBook($p),
            'cash-flow' => $this->reports->cashFlow($p),
            'income-expense' => $this->reports->incomeExpense($p),
            'trial-balance' => $this->reports->trialBalance($p),
        };
    }

    /**
     * AG-087 R4/R5: build a queued report export into $w, inside the job's snapshot (the caller opens it). The count
     * is the first read (SQLite snapshot rule, finding 2). Returns the counts the completeness check needs (finding 5):
     * the detail rows counted in the snapshot, those built from the data, each sheet's rows built and written, and the
     * totals that do not foot. Throws ExportFailure over_limit when the data grew past the limit.
     *
     * @return array{rows: int, built: int, sheets: array<int, array{built: int, written: int}>, unfooted: string[]}
     */
    public function buildFile(string $name, ReportParams $p, User $user, XlsxStreamWriter $w, string $extracted): array
    {
        $data = $this->prebuild($name, $p);
        $rows = $this->countRows($name, $p, $data);
        ReadSnapshot::hook('counted');
        if ($rows > BackgroundExports::maxRows()) {
            throw ExportFailure::overLimit($rows);
        }
        $data ??= $this->build($name, $p);
        $head = $this->header($name, $p, $data, $user, $extracted);
        $sheets = [];
        foreach ($this->sheets($name, $data) as $s) {
            $sheets[] = ['built' => count($s['rows']), 'written' => $w->sheet($s['title'], $head['lines'], $s['columns'], $s['rows'], $s['notes'] ?? [])];
        }

        return ['rows' => $rows, 'built' => $this->builtRows($name, $data), 'sheets' => $sheets,
            'unfooted' => [...$this->unfooted($name, $data), ...$this->unconfirmed($name, $p, $data)]];
    }

    /**
     * Finding 5: each total printed equals the sum of the rows written for it. Returns the totals that do not foot
     * (empty = complete). All sums in integer cents.
     *
     * @return string[]
     */
    public function unfooted(string $name, array $d): array
    {
        $c = fn ($v) => Money::toCents((string) ($v ?? '0'));
        $sum = fn (array $rows, callable $f) => array_sum(array_map(fn ($r) => $c($f($r)), $rows));
        $bad = [];
        $check = function (string $label, int $printed, int $summed) use (&$bad) {
            if ($printed !== $summed) {
                $bad[] = $label;
            }
        };
        match ($name) {
            'income-expense' => array_map(fn ($t) => $d[$t] === null ? null
                : $check("{$t} total", $c($d[$t]['total']), $sum($d[$t]['categories'], fn ($cat) => $cat['amount'])), ['income', 'expense']),
            'branch-comparison' => array_map(fn ($k) => $check("total {$k}", $c($d['total'][$k]), $sum($d['rows'], fn ($r) => $r[$k])), ReportService::COMPARISON_KEYS),
            'transfers' => $check('net moved', $c($d['net_moved']), $sum($d['movements']['data'], fn ($m) => $m['amount'])),
            'cash-book' => array_map(function ($a) use ($check, $c, $sum) {
                $lines = array_merge(...array_map(fn ($day) => $day['lines'], $a['days'] ?: [['lines' => []]]));
                $check("{$a['account']['name']} receipts", $c($a['receipts']), $sum($lines, fn ($l) => $l['receipt']));
                $check("{$a['account']['name']} payments", $c($a['payments']), $sum($lines, fn ($l) => $l['payment']));
            }, $d['accounts']),
            'trial-balance' => isset($d['sections'])
                ? array_map(fn ($sec) => array_map(fn ($k) => $check("{$sec['branch']['code']} {$k}", $c($sec['totals'][$k]), $sum($sec['rows'], fn ($r) => $r[$k])),
                    ['opening_dr', 'opening_cr', 'period_dr', 'period_cr', 'closing_dr', 'closing_cr']), $d['sections'])
                : array_map(fn ($k) => $check("consolidated {$k}", $c($d['totals'][$k]), $sum($d['rows'], fn ($r) => $r[$k])), ['elimination', 'dr', 'cr']),
            default => null,
        };

        return $bad;
    }

    /**
     * Reviewer m7: checks against a second, independent query in the same snapshot, where the row count alone is not
     * one. Cash book and transfers count with their own COUNT query already (countRows); income and expense counts
     * lines of the built report, so its printed totals are checked against one ungrouped SUM.
     *
     * @return string[]
     */
    public function unconfirmed(string $name, ReportParams $p, array $d): array
    {
        if ($name !== 'income-expense') {
            return [];
        }
        $sums = $this->reports->incomeExpenseTotals($p);
        $bad = [];
        foreach (['income', 'expense'] as $t) {
            if ($d[$t] !== null && Money::toCents((string) $d[$t]['total']) !== ($sums[$t] ?? 0)) {
                $bad[] = "{$t} total differs from the ledger sum";
            }
        }

        return $bad;
    }

    /** Safety margin for dompdf at the PDF cap (about 1,000 lines). Never lowers a higher limit. */
    public static function raisePdfLimits(): void
    {
        // CLI and workers default to 0 (no limit); set_time_limit(120) would impose one on the whole process.
        $limit = (int) ini_get('max_execution_time');
        if ($limit > 0 && $limit < 120) {
            @set_time_limit(120);
        }
        $current = ini_get('memory_limit');
        if ($current !== '-1' && self::bytes((string) $current) < 512 * 1024 * 1024) {
            @ini_set('memory_limit', '512M');
        }
    }

    private static function bytes(string $v): int
    {
        $n = (int) $v;

        return match (strtolower(substr(trim($v), -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }

    /** Header block (finding 4): identical lines at the top of every PDF page and every Excel sheet. */
    public function header(string $name, ReportParams $p, array $data, User $user, ?string $extracted = null): array
    {
        $settings = app(Settings::class);
        $company = $settings->companyName();
        // AG-092 R3: built from the stored file after its sha256 check; null (no logo, no error for the user) otherwise.
        $logo = (string) $settings->logoDataUri();
        $now = now()->setTimezone('Asia/Colombo');
        $printed = 'Printed by '.$user->name.' at '.$settings->formatDate($now->format('Y-m-d')).' '.$now->format('H:i').' (Asia/Colombo)';
        $currency = 'Amounts in '.$settings->currencyCode();
        $basis = $data['basis'] ?? ReportService::basis($name);
        $title = self::TITLES[$name];
        $fy = $data['fiscal_year'] ?? null;
        $notes = [];
        if ($name === 'trial-balance') {
            // Review finding 12: the basis is in the title.
            $title = 'Trial balance - '.(($data['closing_entries'] ?? false) ? 'post-closing' : 'pre-closing');
            // Review finding 5: a year closed again says how often it was reopened; a reopened year says so.
            if ($fy && $fy['closed'] && $fy['reopen_count'] > 0) {
                $notes[] = "Reopened {$fy['reopen_count']} time(s)";
            }
            if ($fy && $fy['reopened']) {
                $notes[] = "{$fy['label']} was reopened on ".$settings->formatDate(substr((string) $fy['reopened']['at'], 0, 10))
                    ." by {$fy['reopened']['by']}: {$fy['reopened']['reason']}";
            }
        }
        if ($name === 'transfers' && ($receipt = $p->extra['receipt'] ?? [])) {
            // AG-052 S5: the Receipt filter in the header.
            $notes[] = $receipt === [TransferReceipts::IN_TRANSIT]
                ? 'Receipt: '.TransferReceipts::IN_TRANSIT_LABEL.' ('.$settings->formatDate($p->to).'), any date up to it'
                : 'Receipt: '.implode(', ', array_map([TransferReceipts::class, 'label'], $receipt));
        }
        // AG-086 R7: the cash flow sends its own label (a range over two fiscal years names both).
        $period = $data['period_label'] ?? self::periodLabel($p->from, $p->to);
        $lines = array_values(array_filter([
            $company,
            $title,
            'Branches: '.$p->branchLabel(),
            'Period: '.$period,
            $currency,
            $basis,
            $printed,
            // AG-087 R3 (finding 1): queued exports only, right after "Printed by".
            $extracted,
            ...$notes,
            $data['provisional'] ? 'PROVISIONAL - period not locked' : null,
        ]));

        return [
            'company' => $company,
            // Only embedded PNG/JPEG (data URIs): dompdf does not fetch remote files, and no SVG.
            'logo' => preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=\s]+$#', $logo) ? $logo : null,
            'title' => $title,
            'branches' => $p->branchLabel(),
            'period' => $period,
            'currency' => $currency,
            'printed' => $printed,
            'basis' => $basis,
            'notes' => $notes,
            'provisional' => $data['provisional'],
            'lines' => $lines,
        ];
    }

    /**
     * Settings spec S6: an exact fiscal year reads "FY 2026-27, <from> to <to>"; a Jan-Dec range keeps the calendar
     * label (only when the fiscal year does not start in January). Dates in the date format setting (S3).
     */
    public static function periodLabel(string $from, string $to): string
    {
        $settings = app(Settings::class);
        $range = $settings->formatDate($from).' to '.$settings->formatDate($to);
        $fy = $settings->fiscalYear($from);
        if ($from === $fy->start() && $to === $fy->end()) {
            return "{$fy->label()}, {$range}";
        }
        $f = CarbonImmutable::parse($from);
        if ($f->format('m-d') === '01-01' && $to === $f->format('Y').'-12-31') {
            return "Year {$f->format('Y')} (calendar), {$range}";
        }

        return $range;
    }

    // ------------------------------------------------------------------ PDF

    private function pdf(string $name, array $head, array $data, string $file): Response
    {
        $view = match ($name) {
            'income-expense' => ['reports.pdf.income-expense', ['data' => $data]],
            'branch-comparison' => ['reports.pdf.branch-comparison', ['data' => $data]],
            'transfers' => ['reports.pdf.transfers', ['data' => $data, 'pages' => $this->carryPages(
                array_map(fn ($m) => $m + ['cents' => ['amount' => Money::toCents($m['amount'])]], $data['movements']['data']), ['amount'], self::PDF_ROWS_LANDSCAPE)]],
            'cash-book' => ['reports.pdf.cash-book', ['data' => $data, 'books' => array_map(fn ($a) => $a + ['pages' => $this->carryPages($this->cashBookLines($a), ['receipt', 'payment'], self::PDF_ROWS_PORTRAIT)], $data['accounts'])]],
            'trial-balance' => ['reports.pdf.trial-balance', ['data' => $data]],
            'cash-flow' => ['reports.pdf.cash-flow', ['data' => $data, 'notes' => self::cashFlowNotes($data)]],
        };
        $pdf = Pdf::loadView($view[0], $view[1] + ['head' => $head])
            ->setPaper('a4', in_array($name, ['transfers', 'branch-comparison', 'trial-balance', 'cash-flow'], true) ? 'landscape' : 'portrait');
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('Helvetica');
        $canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
        ]);
    }

    /** Flatten a cash book account into printable lines: one summary row per day, then its detail lines. */
    private function cashBookLines(array $book): array
    {
        $lines = [];
        foreach ($book['days'] as $d) {
            $lines[] = ['kind' => 'day'] + $d + ['cents' => ['receipt' => 0, 'payment' => 0]];
            foreach ($d['lines'] as $l) {
                $lines[] = ['kind' => 'line', 'date' => $d['date']] + $l
                    + ['cents' => ['receipt' => Money::toCents($l['receipt']), 'payment' => Money::toCents($l['payment'])]];
            }
        }

        return $lines;
    }

    /**
     * Split rows into fixed PDF pages, each with Brought forward, page subtotal and Carried forward (finding 5).
     * The last page's Carried forward equals the report total.
     *
     * @return array<int, array{rows: array, bf: array<string,string>, page: array<string,string>, cf: array<string,string>}>
     */
    public function carryPages(array $rows, array $keys, int $perPage): array
    {
        $pages = [];
        $carry = array_fill_keys($keys, 0);
        foreach (array_chunk($rows, $perPage) ?: [[]] as $chunk) {
            $sub = array_fill_keys($keys, 0);
            foreach ($chunk as $r) {
                foreach ($keys as $k) {
                    $sub[$k] += $r['cents'][$k];
                }
            }
            $cf = [];
            foreach ($keys as $k) {
                $cf[$k] = $carry[$k] + $sub[$k];
            }
            $pages[] = [
                'rows' => $chunk,
                'bf' => array_map([Money::class, 'fromCents'], $carry),
                'page' => array_map([Money::class, 'fromCents'], $sub),
                'cf' => array_map([Money::class, 'fromCents'], $cf),
            ];
            $carry = $cf;
        }

        return $pages;
    }

    // ------------------------------------------------------------------ Excel

    /**
     * Sheets as plain data: ['title', 'columns' => [[label, type]], 'rows' => [['cells' => [...], 'bold' => bool]]].
     * Types: text, amount (numeric #,##0.00), date (date cell), percent (numeric 0.0 or text "—").
     */
    private function sheets(string $name, array $d): array
    {
        $row = fn (array $cells, bool $bold = false) => ['cells' => $cells, 'bold' => $bold];

        return match ($name) {
            'income-expense' => collect(['income' => 'Income', 'expense' => 'Expenses'])
                ->filter(fn ($label, $type) => $d[$type] !== null)
                ->map(function ($label, $type) use ($d, $row) {
                    $rows = [];
                    // Categories review finding 8: the ledger code first (as in the PDF and the trial balance), a "<code> only"
                    // tag on branch-only subs.
                    foreach ($d[$type]['categories'] as $c) {
                        $rows[] = $row([$c['code'] ?? '', $c['name'].($c['is_active'] ? '' : ' (Inactive)'), '', $c['amount'], $c['percent'] ?? '—'], true);
                        foreach ($c['subs'] as $s) {
                            $rows[] = $row([$s['code'] ?? '', $c['name'], $s['name'].(empty($s['branch_only']) ? '' : " ({$s['branch_only']} only)").($s['is_active'] ? '' : ' (Inactive)'),
                                $s['amount'], $s['percent'] ?? '—']);
                        }
                    }
                    $rows[] = $row(['', $type === 'income' ? 'Total income' : 'Total expenses', '', $d[$type]['total'], null], true);
                    if ($type === 'expense' && $d['net'] !== null) {
                        $rows[] = $row(['', 'Net (income - expenses)', '', $d['net'], null], true);
                    }

                    return ['title' => $label, 'columns' => [['Code', 'text'], ['Category', 'text'], ['Sub-category', 'text'], ['Amount', 'amount'], ['% of type total', 'percent']], 'rows' => $rows];
                })->values()->all(),

            'branch-comparison' => [[
                'title' => 'Branch Comparison',
                'columns' => [['Branch', 'text'], ['Opening balance', 'amount'], ['Capital introduced', 'amount'], ['Funds received', 'amount'], ['Income', 'amount'],
                    ['Expenses', 'amount'], ['Transfers in', 'amount'], ['Transfers out', 'amount'], ['Closing balance', 'amount']],
                'rows' => array_merge(
                    array_map(fn ($r) => $row(array_merge(
                        [$r['branch']['code'].' '.$r['branch']['name'].($r['branch']['status'] === 'active' ? '' : ' (Inactive)')],
                        array_map(fn ($k) => $r[$k], ReportService::COMPARISON_KEYS))), $d['rows']),
                    [$row(array_merge(['Total'], array_map(fn ($k) => $d['total'][$k], ReportService::COMPARISON_KEYS)), true)],
                ),
                'notes' => array_filter([
                    'Closing = Opening + Capital introduced + Funds received + Income - Expenses + Transfers in - Transfers out. Internal transfers between accounts of the same branch are not shown.',
                    $d['subset'] ? 'Transfers with branches not selected are included; inter-branch amounts will not net to zero.' : null,
                ]),
            ]],

            'transfers' => [
                [
                    'title' => 'Movements',
                    'columns' => [['Date', 'date'], ['Ref no', 'text'], ['Type', 'text'], ['From', 'text'], ['To', 'text'], ['Amount', 'amount'],
                        ['Status', 'text'], ['Receipt', 'text'], ['Note', 'text'], ['Created by', 'text']],
                    'rows' => array_merge(
                        array_map(fn ($m) => $row([$m['txn_date'], $m['ref_no'], self::movementLabel($m), $m['from']['branch_code'].' - '.$m['from']['account'],
                            $m['to']['branch_code'].' - '.$m['to']['account'], $m['amount'], self::statusLabel($m), self::receiptLabel($m), $m['description'], $m['created_by']]), $d['movements']['data']),
                        [$row(['Net moved (reversals deducted)', '', '', '', '', $d['net_moved'], '', '', '', ''], true)],
                    ),
                ],
                $this->matrixSheet($d, $row),
                // AG-051 S7: only when something is pending.
                ...(($d['pending_approval']['count'] ?? 0) > 0 ? [[
                    'title' => 'Pending approval',
                    'columns' => [['Requested on', 'date'], ['Ref no', 'text'], ['Type', 'text'], ['From', 'text'], ['To', 'text'], ['Amount', 'amount']],
                    'rows' => array_merge(
                        array_map(fn ($m) => $row([$m['requested_date'], $m['ref_no'], ReportService::typeLabel($m['type']), $m['from']['branch_code'].' - '.$m['from']['account'],
                            $m['to']['branch_code'].' - '.$m['to']['account'], $m['amount']]), $d['pending_approval']['data']),
                        [$row(["{$d['pending_approval']['count']} pending", '', '', '', '', $d['pending_approval']['amount']], true)],
                    ),
                    'notes' => ['Pending approval now (not included in Movements, Net moved or the matrix).'],
                ]] : []),
            ],

            'trial-balance' => $this->trialBalanceSheets($d, $row),

            // AG-086: the screen columns (finding 2 order) plus Eliminated; Month is a date cell "mmm yyyy" (finding 7).
            'cash-flow' => [[
                'title' => 'Cash flow',
                'columns' => array_merge([['Month', 'month']], array_map(fn ($c) => [$c, 'amount'], array_values(self::CASH_FLOW_COLUMNS))),
                'rows' => array_merge(
                    array_map(fn ($m) => $row(array_merge([$m['month']], array_map(fn ($k) => $m[$k], array_keys(self::CASH_FLOW_COLUMNS)))), $d['months']),
                    [$row(array_merge(['Total'], array_map(fn ($k) => $d['total'][$k], array_keys(self::CASH_FLOW_COLUMNS))), true)],
                ),
                'notes' => array_merge($d['empty'] ? [ReportService::CASH_FLOW_EMPTY] : [], self::cashFlowNotes($d)),
            ]],

            'cash-book' => array_map(function ($a) use ($row) {
                $rows = [$row(['', 'Opening balance', '', '', '', '', $a['opening']], true)];
                foreach ($a['days'] as $day) {
                    foreach ($day['lines'] as $l) {
                        $rows[] = $row([$day['date'], $l['ref_no'], ReportService::typeLabel($l['type']), $l['description'], $l['receipt'], $l['payment'], null]);
                    }
                    $rows[] = $row([$day['date'], 'Day total (opening '.Money::format($day['opening']).')', '', '', $day['receipts'], $day['payments'], $day['closing']], true);
                }
                $rows[] = $row(['', 'Period total', '', '', $a['receipts'], $a['payments'], $a['closing']], true);

                return [
                    'title' => $a['account']['branch_code'].' '.$a['account']['name'],
                    'columns' => [['Date', 'date'], ['Ref no', 'text'], ['Type', 'text'], ['Description / payee', 'text'], ['Receipt', 'amount'], ['Payment', 'amount'], ['Balance', 'amount']],
                    'rows' => $rows,
                ];
            }, $d['accounts']),
        };
    }

    /** Trial balance sheets: one per branch section (by branch), or one consolidated sheet. */
    private function trialBalanceSheets(array $d, callable $row): array
    {
        $groups = ReportService::TB_GROUPS;
        if ($d['view'] === 'consolidated') {
            $codes = array_column($d['branches'], 'code', 'id');
            $rows = [];
            foreach ($d['rows'] as $r) {
                $rows[] = $row(array_merge([$groups[$r['group']], $r['label']], array_values($r['by_branch']), [$r['elimination'], $r['dr'], $r['cr']]));
            }
            $rows[] = $row(array_merge(['Total', ''], array_values($d['totals']['by_branch']), [$d['totals']['elimination'], $d['totals']['dr'], $d['totals']['cr']]), true);

            return [[
                'title' => 'Consolidated',
                'columns' => array_merge([['Group', 'text'], ['Line', 'text']], array_map(fn ($c) => [$c, 'amount'], array_values($codes)),
                    [['Eliminations', 'amount'], ['Consolidated Dr', 'amount'], ['Consolidated Cr', 'amount']]),
                'rows' => $rows,
                'notes' => array_filter([
                    'Branch columns are closing balances, signed: debit positive, credit negative.',
                    $d['subset'] ? 'Inter-branch balances with branches not selected are not eliminated.' : null,
                    $d['balanced'] ? null : 'Out of balance by '.Money::format($d['difference']).'. Run ledger:verify.',
                ]),
            ]];
        }

        return array_map(function ($sec) use ($row) {
            $rows = [];
            foreach ($sec['rows'] as $r) {
                $a = $r['account'];
                $rows[] = $row([$a['code'], $a['name'].($a['status'] === 'active' ? '' : ' ('.ucfirst($a['status']).')'), ucfirst($a['class']),
                    $r['opening_dr'], $r['opening_cr'], $r['period_dr'], $r['period_cr'], $r['closing_dr'], $r['closing_cr']]);
            }
            $t = $sec['totals'];
            $rows[] = $row(['', 'Total', '', $t['opening_dr'], $t['opening_cr'], $t['period_dr'], $t['period_cr'], $t['closing_dr'], $t['closing_cr']], true);

            return [
                'title' => $sec['branch']['code'].' '.$sec['branch']['name'],
                'columns' => [['Code', 'text'], ['Account', 'text'], ['Class', 'text'], ['Opening Dr', 'amount'], ['Opening Cr', 'amount'],
                    ['Period debits', 'amount'], ['Period credits', 'amount'], ['Closing Dr', 'amount'], ['Closing Cr', 'amount']],
                'rows' => $rows,
                'notes' => array_filter([$sec['balanced'] ? null : 'Out of balance by '.Money::format($sec['difference']).'. Run ledger:verify.']),
            ];
        }, $d['sections']);
    }

    private function matrixSheet(array $d, callable $row): array
    {
        $m = $d['matrix'];
        $cell = [];
        foreach ($m['cells'] as $c) {
            $cell[$c['from_id']][$c['to_id']] = $c['amount'];
        }
        $rows = [];
        foreach ($m['branches'] as $a) {
            $cells = [$a['code']];
            foreach ($m['branches'] as $b) {
                $cells[] = $a['id'] === $b['id'] ? '—' : ($cell[$a['id']][$b['id']] ?? null);
            }
            $cells[] = $a['in_scope'] ? $m['sent'][$a['id']] : null;
            $rows[] = $row($cells);
        }
        $rows[] = $row(array_merge(['Total received'], array_map(fn ($b) => $b['in_scope'] ? $m['received'][$b['id']] : null, $m['branches']), [null]), true);
        $rows[] = $row(array_merge(['Net (received - sent)'], array_map(fn ($b) => $b['in_scope'] ? $m['net'][$b['id']] : null, $m['branches']), [null]), true);

        return [
            'title' => 'Matrix',
            'columns' => array_merge([['From \\ To', 'text']], array_map(fn ($b) => [$b['code'], 'amount'], $m['branches']), [['Total sent', 'amount']]),
            'rows' => $rows,
            'notes' => array_filter([
                'Cell = net amount sent from the row branch to the column branch (reversals deducted).',
                $d['subset'] ? 'Transfers with branches not selected are included; inter-branch amounts will not net to zero.' : null,
            ]),
        ];
    }

    public static function movementLabel(array $m): string
    {
        $label = ReportService::MOVEMENT_LABELS[$m['movement_type']] ?? Str::headline($m['movement_type']);

        return $m['status'] === 'reversal' ? "Reversal ({$label})" : $label;
    }

    /** AG-052 S5: the receipt label, "—" when none (reversal rows, transfers not requiring one). */
    public static function receiptLabel(array $m): string
    {
        return ($m['receipt_status'] ?? null) ? TransferReceipts::label($m['receipt_status']) : '—';
    }

    public static function statusLabel(array $m): string
    {
        return match ($m['status']) {
            'reversal' => "Reversal of {$m['reversal_of_ref']}",
            'reversed' => 'Reversed',
            default => 'Posted',
        };
    }
}
