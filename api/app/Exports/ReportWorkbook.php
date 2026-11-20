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
        $titles = self::titles(array_column($this->sheets, 'title'));

        return array_map(fn (array $s, string $title) => new ReportSheet($title, $this->headerLines, $s['columns'], $s['rows'], $s['notes'] ?? [], $s['intro'] ?? []),
            $this->sheets, $titles);
    }

    /**
     * Excel sheet names: max 31 characters, no []:*?/\ and unique within the workbook. Shared with the background
     * export writer (AG-087 R5), so both files name their sheets alike.
     *
     * @param  string[]  $titles
     * @return string[]
     */
    public static function titles(array $titles): array
    {
        $used = [];

        return array_map(function (string $t) use (&$used) {
            $base = mb_substr(trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $t)), 0, 28) ?: 'Sheet';
            $title = $base;
            for ($i = 2; in_array(mb_strtolower($title), $used, true); $i++) {
                $title = "{$base} {$i}";
            }
            $used[] = mb_strtolower($title);

            return $title;
        }, array_values($titles));
    }
}
