<?php

namespace App\Exports;

use App\Support\Settings;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;
use XMLWriter;
use ZipArchive;

/**
 * AG-087 R5: writes a background export as .xlsx row by row, so a file of 100,000 rows never sits in memory as one
 * spreadsheet (PhpSpreadsheet keeps every cell in memory: about 1 GB at that size). The layout and the cell rules are
 * those of ReportSheet (the synchronous writer): header lines (the first two and PROVISIONAL bold), a blank row, the
 * bold column labels with the pane frozen below them, typed data rows, then the notes. Every cell has an explicit
 * type, so nothing a user typed is read as a formula: amounts are numeric (#,##0.00), dates and months are date
 * cells, text is a string cell, and text starting with = + - @ gets the quote prefix. No formulas, no shared strings.
 *
 * Sheets are written to temporary files beside the target and zipped on close(); rows can come from a generator.
 */
final class XlsxStreamWriter
{
    // cellXfs indexes (styles.xml below): [regular, bold] per kind.
    private const XF = ['text' => [0, 1], 'amount' => [2, 3], 'percent' => [4, 5], 'date' => [6, 7], 'month' => [8, 9], 'quoted' => [10, 11]];

    /** @var array<int, array{title: string, file: string}> */
    private array $sheets = [];

    private array $temp = [];

    private bool $closed = false;

    public function __construct(private string $path) {}

    /**
     * Write one sheet. Returns the number of data rows written (the completeness check compares it, finding 5).
     *
     * @param  string[]  $headerLines
     * @param  array<int, array{0: string, 1: string}>  $columns  [label, type]
     * @param  iterable<array{cells: array, bold: bool}>  $rows
     * @param  string[]  $notes
     */
    public function sheet(string $title, array $headerLines, array $columns, iterable $rows, array $notes = []): int
    {
        $data = $this->tempFile();
        $x = new XMLWriter;
        $x->openUri($data);
        $widths = [];
        $r = 1;
        foreach ($headerLines as $i => $line) {
            $this->row($x, $r, [[1, 'text', $line, $i < 2 || str_starts_with($line, 'PROVISIONAL')]], $widths);
            $r++;
        }
        $r++;
        $headRow = $r;
        $this->row($x, $r, array_map(fn ($c, $i) => [$i + 1, 'text', $c[0], true], $columns, array_keys($columns)), $widths);
        $written = 0;
        foreach ($rows as $row) {
            $r++;
            $cells = [];
            foreach ($row['cells'] as $c => $v) {
                $cells[] = [$c + 1, $columns[$c][1] ?? 'text', $v, (bool) $row['bold']];
            }
            $this->row($x, $r, $cells, $widths);
            $written++;
            if ($written % 1000 === 0) {
                $x->flush();
            }
        }
        $r++;
        foreach ($notes as $note) {
            $this->row($x, ++$r, [[1, 'text', $note, false]], $widths);
        }
        $x->flush();
        unset($x);

        $file = $this->tempFile();
        $out = fopen($file, 'wb');
        fwrite($out, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$headRow.'" topLeftCell="A'.($headRow + 1).'" activePane="bottomLeft" state="frozen"/>'
            .'<selection pane="bottomLeft" activeCell="A'.($headRow + 1).'" sqref="A'.($headRow + 1).'"/></sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>');
        if ($columns) {
            fwrite($out, '<cols>');
            foreach (range(1, count($columns)) as $c) {
                $w = min(255, round(($widths[$c] ?? 8) * 1.1 + 2, 2));
                fwrite($out, '<col min="'.$c.'" max="'.$c.'" width="'.$w.'" customWidth="1"/>');
            }
            fwrite($out, '</cols>');
        }
        fwrite($out, '<sheetData>');
        $in = fopen($data, 'rb');
        stream_copy_to_stream($in, $out);
        fclose($in);
        @unlink($data);
        fwrite($out, '</sheetData><pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/></worksheet>');
        fclose($out);

        $this->sheets[] = ['title' => $title, 'file' => $file];

        return $written;
    }

    /** Zip the workbook to $path. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $zip = new ZipArchive;
        if ($zip->open($this->path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The export file could not be created.');
        }
        $n = count($this->sheets);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .implode('', array_map(fn ($i) => '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', range(1, max($n, 1))))
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $titles = ReportWorkbook::titles(array_column($this->sheets, 'title'));
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.implode('', array_map(fn ($t, $i) => '<sheet name="'.self::attr($t).'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>', $titles, array_keys($titles))).'</sheets>'
            .'</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .implode('', array_map(fn ($i) => '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>', range(1, $n)))
            .'<Relationship Id="rId'.($n + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', $this->styles());
        foreach ($this->sheets as $i => $s) {
            $zip->addFile($s['file'], 'xl/worksheets/sheet'.($i + 1).'.xml');
        }
        $ok = $zip->close();
        $this->cleanup();
        if (! $ok) {
            throw new RuntimeException('The export file could not be written.');
        }
    }

    /** Remove the temporary sheet files (also after a failure). */
    public function cleanup(): void
    {
        foreach ($this->temp as $f) {
            @unlink($f);
        }
        $this->temp = [];
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    // ------------------------------------------------------------------ cells

    /** @param  array<int, array{0: int, 1: string, 2: mixed, 3: bool}>  $cells  [column, type, value, bold] */
    private function row(XMLWriter $x, int $r, array $cells, array &$widths): void
    {
        $x->startElement('row');
        $x->writeAttribute('r', (string) $r);
        foreach ($cells as [$col, $type, $v, $bold]) {
            $this->cell($x, $col, $r, $type, $v, $bold, $widths);
        }
        $x->endElement();
    }

    /** ReportSheet::cell, as XML. */
    private function cell(XMLWriter $x, int $col, int $r, string $type, mixed $v, bool $bold, array &$widths): void
    {
        if ($v === null || $v === '') {
            return;
        }
        $ref = self::column($col).$r;
        $b = $bold ? 1 : 0;
        if ($type === 'amount' && is_string($v) && preg_match('/^-?\d+\.\d{2}$/', $v)) {
            $this->number($x, $ref, $v, self::XF['amount'][$b]);
            $len = strlen(number_format((float) $v, 2));
        } elseif ($type === 'percent' && is_string($v) && is_numeric($v)) {
            $this->number($x, $ref, $v, self::XF['percent'][$b]);
            $len = strlen($v);
        } elseif ($type === 'date' && is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            $this->number($x, $ref, (string) ExcelDate::stringToExcel($v), self::XF['date'][$b]);
            $len = 11;
        } elseif ($type === 'month' && is_string($v) && preg_match('/^\d{4}-\d{2}$/', $v)) {
            $this->number($x, $ref, (string) ExcelDate::stringToExcel($v.'-01'), self::XF['month'][$b]);
            $len = 8;
        } else {
            $text = self::clean((string) $v);
            $quoted = $text !== '' && in_array($text[0], ['=', '+', '-', '@'], true);
            $x->startElement('c');
            $x->writeAttribute('r', $ref);
            $x->writeAttribute('s', (string) self::XF[$quoted ? 'quoted' : 'text'][$b]);
            $x->writeAttribute('t', 'inlineStr');
            $x->startElement('is');
            $x->startElement('t');
            $x->writeAttribute('xml:space', 'preserve');
            $x->text($text);
            $x->endElement();
            $x->endElement();
            $x->endElement();
            $len = mb_strlen($text);
        }
        $widths[$col] = max($widths[$col] ?? 0, $len);
    }

    private function number(XMLWriter $x, string $ref, string $v, int $style): void
    {
        $x->startElement('c');
        $x->writeAttribute('r', $ref);
        $x->writeAttribute('s', (string) $style);
        $x->writeElement('v', $v);
        $x->endElement();
    }

    private function styles(): string
    {
        $formats = [164 => '#,##0.00', 165 => '0.0', 166 => app(Settings::class)->excelDateFormat(), 167 => 'mmm yyyy'];
        $xf = fn (int $fmt, int $font, bool $quote = false) => '<xf numFmtId="'.$fmt.'" fontId="'.$font.'" fillId="0" borderId="0" xfId="0"'
            .($fmt ? ' applyNumberFormat="1"' : '').($font ? ' applyFont="1"' : '').($quote ? ' quotePrefix="1"' : '').'/>';
        $xfs = [$xf(0, 0), $xf(0, 1), $xf(164, 0), $xf(164, 1), $xf(165, 0), $xf(165, 1), $xf(166, 0), $xf(166, 1), $xf(167, 0), $xf(167, 1),
            $xf(0, 0, true), $xf(0, 1, true)];

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="'.count($formats).'">'.implode('', array_map(fn ($id, $code) => '<numFmt numFmtId="'.$id.'" formatCode="'.self::attr($code).'"/>', array_keys($formats), $formats)).'</numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="'.count($xfs).'">'.implode('', $xfs).'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private function tempFile(): string
    {
        // Next to the file on the private disk (mode 0600), never in a shared temp folder; exports:prune removes any
        // left behind by a killed worker (R10.3).
        $f = tempnam(dirname($this->path), 'agx');
        if ($f === false) {
            throw new RuntimeException('No temporary file for the export.');
        }
        $this->temp[] = $f;

        return $f;
    }

    private static function column(int $n): string
    {
        $s = '';
        for (; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26).$s;
        }

        return $s;
    }

    /** Characters XML 1.0 cannot hold are dropped (Excel could not open the file otherwise). */
    private static function clean(string $v): string
    {
        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $v);
    }

    private static function attr(string $v): string
    {
        return htmlspecialchars(self::clean($v), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
