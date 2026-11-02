<?php
declare(strict_types=1);

namespace LeenTrack\Core;

/** RFC-4180 reader (same behaviour as the source's parseCSV) and an injection-safe writer. */
final class Csv
{
    /** @return list<list<string>> rows; trailing blank lines dropped; every field trimmed (as in the source) */
    public static function parse(string $text): array
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) { $text = substr($text, 3); }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $rows = [];
        $row = [];
        $val = '';
        $inQ = false;
        $n = strlen($text);
        for ($i = 0; $i < $n; $i++) {
            $c = $text[$i];
            if ($inQ) {
                if ($c === '"') {
                    if (($text[$i + 1] ?? '') === '"') { $val .= '"'; $i++; } else { $inQ = false; }
                } else {
                    $val .= $c;
                }
            } elseif ($c === '"') {
                $inQ = true;
            } elseif ($c === ',') {
                $row[] = trim($val); $val = '';
            } elseif ($c === "\n") {
                $row[] = trim($val);
                if (array_filter($row, fn ($f) => $f !== '')) { $rows[] = $row; }
                $row = []; $val = '';
            } else {
                $val .= $c;
            }
        }
        if ($val !== '' || $row) {
            $row[] = trim($val);
            if (array_filter($row, fn ($f) => $f !== '')) { $rows[] = $row; }
        }
        return $rows;
    }

    /**
     * Spreadsheet formula-injection guard: text cells that start with = + - @ TAB CR are prefixed with a single quote
     * so Excel/Sheets treat them as text. Apply ONLY to user-supplied text, never to our own numeric columns.
     */
    public static function safeText(string $v): string
    {
        return $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
    }

    /** @param list<string|int|float|null> $cells already-guarded values */
    public static function line(array $cells): string
    {
        return implode(',', array_map(function ($c) {
            $c = (string) ($c ?? '');
            return preg_match('/[",\r\n]/', $c) ? '"' . str_replace('"', '""', $c) . '"' : $c;
        }, $cells)) . "\r\n";
    }
}
