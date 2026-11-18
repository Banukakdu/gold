<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** One sheet per report section, each with the same header lines on top (accounting review finding 4). */
class ReportWorkbook implements WithMultipleSheets
{
    /** @param  string[]  $headerLines */
    public function __construct(private array $headerLines, private array $sheets) {}

    public function sheets(): array
    {
        $used = [];

        return array_map(function (array $s) use (&$used) {
            // Excel sheet names: max 31 characters, no []:*?/\ and unique within the workbook.
            $base = mb_substr(trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $s['title'])), 0, 28) ?: 'Sheet';
            $title = $base;
            for ($i = 2; in_array(mb_strtolower($title), $used, true); $i++) {
                $title = "{$base} {$i}";
            }
            $used[] = mb_strtolower($title);

            return new ReportSheet($title, $this->headerLines, $s['columns'], $s['rows'], $s['notes'] ?? []);
        }, $this->sheets);
    }
}
