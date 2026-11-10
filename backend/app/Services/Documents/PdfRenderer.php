<?php
declare(strict_types=1);

namespace LeenTrack\Services\Documents;

/**
 * Dependency-free PDF 1.4 writer for receipts, invoices and payment receipts (no Composer, no GD/Imagick: Truehost-safe).
 * Typeset in Courier / Courier-Bold, a fixed-width face, so column alignment and line wrapping are exact. Takes the API document
 * model (strings) produced by DocumentBuilder::toApi. Limits, stated plainly: Latin-1 text only (anything else is transliterated or
 * shown as "?"), the currency CODE is printed instead of its symbol (symbols like the naira sign are not in the PDF base fonts),
 * and the business logo is not embedded (clients that draw their own receipts still get it from the JSON).
 */
final class PdfRenderer
{
    private const COLS = 78;                 // characters per line at 9 pt (78 * 5.4 pt = 421 pt, inside A4 with 40 pt margins)
    private const FONT = 9.0;
    private const LEAD = 12.0;
    private const LEFT = 58.0;
    private const TOP = 790.0;
    private const BOTTOM = 56.0;

    /** @param array<string,mixed> $d @return string PDF bytes */
    public static function render(array $d): string
    {
        $lines = self::layout($d);
        $perPage = (int) floor((self::TOP - self::BOTTOM) / self::LEAD);
        $pages = array_chunk($lines, max(1, $perPage));
        $objs = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>', 4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold /Encoding /WinAnsiEncoding >>'];
        $kids = [];
        $n = 5;
        foreach ($pages as $i => $pg) {
            $content = "BT\n" . self::LEAD . " TL\n" . self::LEFT . ' ' . self::TOP . " Td\n";
            foreach ($pg as [$text, $bold]) {
                $content .= '/' . ($bold ? 'F2' : 'F1') . ' ' . self::FONT . " Tf\n(" . self::esc($text) . ") Tj T*\n";
            }
            $content .= 'ET';
            if (count($pages) > 1) { $content .= "\nBT /F1 7 Tf 58 30 Td (" . self::esc('Page ' . ($i + 1) . ' of ' . count($pages)) . ') Tj ET'; }
            $objs[$n] = "<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream";
            $objs[$n + 1] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $n 0 R >>";
            $kids[] = ($n + 1) . ' 0 R';
            $n += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $title = self::latin1((string) ($d['title'] ?? 'Document') . ' ' . (string) ($d['number'] ?? ''));
        $objs[$n] = '<< /Title (' . self::esc($title) . ') /Producer (LeenTrack) /CreationDate (D:' . gmdate('YmdHis') . 'Z) >>';
        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $num => $body) {
            $offsets[$num] = strlen($out);
            $out .= "$num 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $off) { $out .= sprintf("%010d 00000 n \n", $off); }
        return $out . "trailer\n<< /Size " . (count($objs) + 1) . ' /Root 1 0 R /Info ' . $n . " 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    /** @param array<string,mixed> $d @return list<array{0:string,1:bool}> [text, bold] */
    private static function layout(array $d): array
    {
        $cur = (string) ($d['currencyCode'] ?? '');
        $m = fn ($v) => ($cur !== '' ? $cur . ' ' : '') . self::money((string) $v);
        $L = [];
        $add = function (string $t, bool $bold = false) use (&$L) { foreach (self::wrap(self::latin1($t)) as $w) { $L[] = [$w, $bold]; } };
        $rule = function () use (&$L) { $L[] = [str_repeat('-', self::COLS), false]; };
        $b = $d['business'] ?? [];
        $add((string) ($b['name'] ?? ''), true);
        foreach (['address', 'phone', 'email'] as $k) { if (!empty($b[$k])) { $add((string) $b[$k]); } }
        $L[] = ['', false];
        $add((string) ($d['title'] ?? ''), true);
        $add('No: ' . ($d['number'] ?? '') . '   Date: ' . self::date((string) ($d['date'] ?? '')));
        $status = $d['status']['label'] ?? null;
        if ($status) { $add('Status: ' . $status, true); }
        if (!empty($d['revisedAt'])) { $add('Revised: ' . self::date((string) $d['revisedAt'])); }
        if (!empty($d['customer'])) {
            $c = $d['customer'];
            $L[] = ['', false];
            $add('Customer: ' . ($c['name'] ?? ''));
            foreach (['phone', 'email', 'address'] as $k) { if (!empty($c[$k])) { $add('          ' . $c[$k]); } }
        }
        $L[] = ['', false];
        $rule();
        $L[] = [self::row('Item', 'Qty', 'Price', 'Total'), true];
        $rule();
        foreach ($d['items'] ?? [] as $i) {
            $name = self::wrap(self::latin1((string) $i['name']), 36);
            $first = true;
            foreach ($name as $part) {
                $L[] = [$first ? self::row($part, (string) $i['quantity'], self::money((string) $i['unitPrice']), self::money((string) $i['total'])) : $part, false];
                $first = false;
            }
        }
        $rule();
        $tot = function (string $label, string $val, bool $bold = false) use (&$L) { $L[] = [str_pad($label, self::COLS - 20) . str_pad($val, 20, ' ', STR_PAD_LEFT), $bold]; };
        $tot('Subtotal', $m($d['subtotal'] ?? '0'));
        if (self::pos($d['discount'] ?? '0')) { $tot('Discount', '-' . $m($d['discount'])); }
        if (self::pos($d['tax'] ?? '0')) { $tot('Tax' . (!empty($d['taxRate']) ? ' (' . $d['taxRate'] . '%)' : ''), $m($d['tax'])); }
        $tot('TOTAL', $m($d['total'] ?? '0'), true);
        $tot('Paid', $m($d['amountPaid'] ?? '0'));
        if (self::pos($d['balanceDue'] ?? '0')) { $tot('BALANCE DUE', $m($d['balanceDue']), true); }
        if (self::pos($d['change'] ?? '0')) { $tot('Change', $m($d['change'])); }
        $L[] = ['', false];
        if (!empty($d['paymentMethod'])) { $add('Payment: ' . $d['paymentMethod']); }
        if (!empty($d['notes'])) { $add('Notes: ' . $d['notes']); }
        if (!empty($b['paymentDetails'])) { $L[] = ['', false]; $add('Payment details: ' . $b['paymentDetails']); }
        if (!empty($b['footerNote'])) { $L[] = ['', false]; $add((string) $b['footerNote']); }
        return $L;
    }

    private static function row(string $name, string $qty, string $price, string $total): string
    {
        return str_pad(substr($name, 0, 36), 36) . str_pad($qty, 6, ' ', STR_PAD_LEFT) . str_pad($price, 16, ' ', STR_PAD_LEFT) . str_pad($total, 20, ' ', STR_PAD_LEFT);
    }

    private static function money(string $v): string
    {
        $neg = str_starts_with($v, '-');
        [$i, $f] = array_pad(explode('.', ltrim($v, '-'), 2), 2, '00');
        return ($neg ? '-' : '') . preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $i) . '.' . str_pad(substr($f, 0, 2), 2, '0');
    }

    private static function pos(mixed $v): bool
    {
        return (float) $v > 0;
    }

    private static function date(string $iso): string
    {
        try { return (new \DateTimeImmutable($iso))->format('Y-m-d H:i') . ' UTC'; } catch (\Throwable) { return $iso; }
    }

    /** @return list<string> */
    private static function wrap(string $s, int $width = self::COLS): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $s) ?: [''] as $para) {
            while (strlen($para) > $width) {
                $cut = strrpos(substr($para, 0, $width + 1), ' ');
                $cut = ($cut === false || $cut < (int) ($width / 2)) ? $width : $cut;
                $out[] = rtrim(substr($para, 0, $cut));
                $para = ltrim(substr($para, $cut));
            }
            $out[] = $para;
        }
        return $out;
    }

    /** Latin-1 (WinAnsi) only; other characters are transliterated or become "?". Control characters are dropped. */
    private static function latin1(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s) ?? '';
        // Locale-independent: map the common symbols that have no WinAnsi slot, then convert; anything else becomes "?".
        $s = strtr($s, ['₦' => 'N', '₵' => 'C', '€' => 'EUR', '£' => 'GBP', '—' => '-', '–' => '-', '‘' => "'", '’' => "'", '“' => '"', '”' => '"', '…' => '...', "\u{00A0}" => ' ']);
        return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    }

    private static function esc(string $s): string
    {
        return strtr($s, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }
}
