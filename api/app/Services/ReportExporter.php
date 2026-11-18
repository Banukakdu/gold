<?php

namespace App\Services;

use App\Exports\ReportWorkbook;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use App\Support\ReportParams;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Synchronous PDF / Excel export of the reports (spec section 5, accounting review finding 4/5).
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
    ];

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
        };
    }

    public function download(string $name, ReportParams $p, string $format, User $user): Response
    {
        // Income & Expense counts lines from the built report: build it once and reuse it.
        $data = match ($name) {
            'income-expense' => $this->reports->incomeExpense($p),
            'trial-balance' => $this->reports->trialBalance($p),
            default => null,
        };
        $rows = $this->countRows($name, $p, $data);
        $cap = $format === 'pdf' ? ReportService::PDF_EXPORT_CAP : ReportService::EXPORT_CAP;
        if ($rows > $cap) {
            $message = $format === 'pdf'
                ? 'This export has '.number_format($rows).' rows; the limit for PDF is '.number_format($cap).'. Use Excel or narrow the date range or branches.'
                : 'This export has '.number_format($rows).' rows; the limit is '.number_format($cap).'. Narrow the date range or branches.';
            abort(response()->json(['message' => $message], 422));
        }
        if ($format === 'pdf') {
            self::raisePdfLimits();
        }

        $data ??= match ($name) {
            'branch-comparison' => $this->reports->branchComparison($p),
            'transfers' => $this->reports->transfers($p, all: true),
            'cash-book' => $this->reports->cashBook($p),
        };
        $head = $this->header($name, $p, $data, $user);
        $file = "{$name}_{$p->from}_{$p->to}.{$format}";

        $response = $format === 'xlsx'
            ? Excel::download(new ReportWorkbook($head['lines'], $this->sheets($name, $data)), $file, ExcelWriter::XLSX)
            : $this->pdf($name, $head, $data, $file);

        $response->headers->set('Content-Disposition', 'attachment; filename="'.$file.'"');
        AuditLog::record('report.exported', null, null, ['name' => $name, 'format' => $format, 'params' => $p->toArray(), 'rows' => $rows],
            $p->branches->count() === 1 ? $p->branches->first()->id : null);

        return $response;
    }

    /** Safety margin for dompdf at the PDF cap (about 1,000 lines). Never lowers a higher limit. */
    public static function raisePdfLimits(): void
    {
        @set_time_limit(120);
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
    public function header(string $name, ReportParams $p, array $data, User $user): array
    {
        $company = (string) Setting::get('company_name', config('app.name'));
        $logo = (string) Setting::get('company_logo', '');
        $printed = 'Printed by '.$user->name.' at '.now()->setTimezone('Asia/Colombo')->format('Y-m-d H:i').' (Asia/Colombo)';
        $lines = array_values(array_filter([
            $company,
            self::TITLES[$name],
            'Branches: '.$p->branchLabel(),
            'Period: '.self::periodLabel($p->from, $p->to),
            'Amounts in LKR',
            ReportService::basis($name),
            $printed,
            $data['provisional'] ? 'PROVISIONAL - period not locked' : null,
        ]));

        return [
            'company' => $company,
            // Only embedded PNG/JPEG (data URIs): dompdf does not fetch remote files, and no SVG.
            'logo' => preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=\s]+$#', $logo) ? $logo : null,
            'title' => self::TITLES[$name],
            'branches' => $p->branchLabel(),
            'period' => self::periodLabel($p->from, $p->to),
            'printed' => $printed,
            'basis' => ReportService::basis($name),
            'provisional' => $data['provisional'],
            'lines' => $lines,
        ];
    }

    /** Finding 11: a full calendar year is labelled as such until the fiscal-year setting exists. */
    public static function periodLabel(string $from, string $to): string
    {
        $range = "{$from} to {$to}";
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
        };
        $pdf = Pdf::loadView($view[0], $view[1] + ['head' => $head])
            ->setPaper('a4', in_array($name, ['transfers', 'branch-comparison', 'trial-balance'], true) ? 'landscape' : 'portrait');
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
                    foreach ($d[$type]['categories'] as $c) {
                        $rows[] = $row([$c['name'].($c['is_active'] ? '' : ' (Inactive)'), '', $c['amount'], $c['percent'] ?? '—'], true);
                        foreach ($c['subs'] as $s) {
                            $rows[] = $row([$c['name'], $s['name'].($s['is_active'] ? '' : ' (Inactive)'), $s['amount'], $s['percent'] ?? '—']);
                        }
                    }
                    $rows[] = $row([$type === 'income' ? 'Total income' : 'Total expenses', '', $d[$type]['total'], null], true);
                    if ($type === 'expense' && $d['net'] !== null) {
                        $rows[] = $row(['Net (income - expenses)', '', $d['net'], null], true);
                    }

                    return ['title' => $label, 'columns' => [['Category', 'text'], ['Sub-category', 'text'], ['Amount', 'amount'], ['% of type total', 'percent']], 'rows' => $rows];
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
                        ['Status', 'text'], ['Note', 'text'], ['Created by', 'text']],
                    'rows' => array_merge(
                        array_map(fn ($m) => $row([$m['txn_date'], $m['ref_no'], self::movementLabel($m), $m['from']['branch_code'].' - '.$m['from']['account'],
                            $m['to']['branch_code'].' - '.$m['to']['account'], $m['amount'], self::statusLabel($m), $m['description'], $m['created_by']]), $d['movements']['data']),
                        [$row(['Net moved (reversals deducted)', '', '', '', '', $d['net_moved'], '', '', ''], true)],
                    ),
                ],
                $this->matrixSheet($d, $row),
            ],

            'trial-balance' => $this->trialBalanceSheets($d, $row),

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

        return array_map(function ($sec) use ($row, $groups) {
            $rows = [];
            foreach ($sec['rows'] as $r) {
                $a = $r['account'];
                $rows[] = $row([$a['code'], $a['name'].($a['status'] === 'active' ? '' : ' ('.ucfirst($a['status']).')'), $groups[$a['group']],
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

    public static function statusLabel(array $m): string
    {
        return match ($m['status']) {
            'reversal' => "Reversal of {$m['reversal_of_ref']}",
            'reversed' => 'Reversed',
            default => 'Posted',
        };
    }
}
