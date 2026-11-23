<?php

namespace App\Exports;

use App\Support\Settings;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Writes every cell with an explicit type, so nothing a user typed is ever read as a formula:
 * amounts are numeric (#,##0.00), integers (counts, days) are numeric, dates (and months, date-times) are date cells, text is a string cell; text starting with = + - @
 * gets Excel's leading-apostrophe (quote prefix) so it stays literal when the cell is edited (spec section 5).
 */
class ReportSheet implements WithEvents, WithTitle
{
    /**
     * @param  string[]  $headerLines
     * @param  array<int, array{0: string, 1: string}>  $columns  [label, type]
     * @param  array<int, array{cells: array, bold: bool}>  $rows
     * @param  string[]  $notes
     * @param  string[]  $intro  lines between the header and the table (AG-033 QA D2: the account line)
     */
    public function __construct(
        private string $title,
        private array $headerLines,
        private array $columns,
        private array $rows,
        private array $notes = [],
        private array $intro = [],
    ) {}

    public function title(): string
    {
        return $this->title;
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $e) => $this->write($e->sheet->getDelegate())];
    }

    private function write(Worksheet $ws): void
    {
        $r = 1;
        foreach ($this->headerLines as $i => $line) {
            $this->text($ws, 1, $r, $line);
            if ($i < 2 || str_starts_with($line, 'PROVISIONAL')) {
                $ws->getStyle([1, $r])->getFont()->setBold(true);
            }
            $r++;
        }
        $r++;
        foreach ($this->intro as $line) {
            $this->text($ws, 1, $r, $line);
            $ws->getStyle([1, $r])->getFont()->setBold(true);
            $r++;
        }
        if ($this->intro) {
            $r++;
        }

        $headRow = $r;
        foreach ($this->columns as $c => [$label]) {
            $this->text($ws, $c + 1, $r, $label);
        }
        $last = Coordinate::stringFromColumnIndex(count($this->columns));
        $ws->getStyle("A{$r}:{$last}{$r}")->getFont()->setBold(true);
        $ws->freezePane('A'.($headRow + 1));

        foreach ($this->rows as $row) {
            $r++;
            foreach ($row['cells'] as $c => $v) {
                $this->cell($ws, $c + 1, $r, $this->columns[$c][1] ?? 'text', $v);
            }
            if ($row['bold']) {
                $ws->getStyle("A{$r}:{$last}{$r}")->getFont()->setBold(true);
            }
        }
        $r++;
        foreach ($this->notes as $note) {
            $this->text($ws, 1, ++$r, $note);
        }

        foreach (range(1, count($this->columns)) as $c) {
            $ws->getColumnDimensionByColumn($c)->setAutoSize(true);
        }
    }

    private function cell(Worksheet $ws, int $col, int $row, string $type, mixed $v): void
    {
        if ($v === null || $v === '') {
            return;
        }
        if ($type === 'amount' && is_string($v) && preg_match('/^-?\d+\.\d{2}$/', $v)) {
            $ws->setCellValueExplicit([$col, $row], $v, DataType::TYPE_NUMERIC);
            $ws->getStyle([$col, $row])->getNumberFormat()->setFormatCode('#,##0.00');
        } elseif ($type === 'integer' && (is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', $v)))) {
            $ws->setCellValueExplicit([$col, $row], (int) $v, DataType::TYPE_NUMERIC);
            $ws->getStyle([$col, $row])->getNumberFormat()->setFormatCode('0');
        } elseif ($type === 'percent' && is_string($v) && is_numeric($v)) {
            $ws->setCellValueExplicit([$col, $row], $v, DataType::TYPE_NUMERIC);
            $ws->getStyle([$col, $row])->getNumberFormat()->setFormatCode('0.0');
        } elseif ($type === 'date' && is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            $ws->setCellValueExplicit([$col, $row], ExcelDate::stringToExcel($v), DataType::TYPE_NUMERIC);
            $ws->getStyle([$col, $row])->getNumberFormat()->setFormatCode(app(Settings::class)->excelDateFormat()); // AG-092 S3
        } elseif ($type === 'datetime' && is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v)) {
            // AG-033d: a date-time cell (the value is already in Asia/Colombo), shown with the date format setting and HH:mm.
            $ws->setCellValueExplicit([$col, $row], ExcelDate::stringToExcel($v.':00'), DataType::TYPE_NUMERIC);
            $ws->getStyle([$col, $row])->getNumberFormat()->setFormatCode(app(Settings::class)->excelDateFormat().' hh:mm');
        } elseif ($type === 'month' && is_string($v) && preg_match('/^\d{4}-\d{2}$/', $v)) {
            // AG-086 review finding 7: a real date cell (1st of the month) shown as "Oct 2026".
            $ws->setCellValueExplicit([$col, $row], ExcelDate::stringToExcel($v.'-01'), DataType::TYPE_NUMERIC);
            $ws->getStyle([$col, $row])->getNumberFormat()->setFormatCode('mmm yyyy');
        } else {
            $this->text($ws, $col, $row, (string) $v);
        }
    }

    private function text(Worksheet $ws, int $col, int $row, string $v): void
    {
        $ws->setCellValueExplicit([$col, $row], $v, DataType::TYPE_STRING);
        if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) {
            $ws->getStyle([$col, $row])->setQuotePrefix(true);
        }
    }
}
