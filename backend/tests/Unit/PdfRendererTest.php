<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Unit;

use LeenTrack\Services\Documents\PdfRenderer;
use PHPUnit\Framework\TestCase;

final class PdfRendererTest extends TestCase
{
    /** @return array<string,mixed> */
    private function doc(array $over = []): array
    {
        return $over + ['kind' => 'invoice', 'title' => 'INVOICE', 'number' => 'INV-1', 'date' => '2026-03-02T10:00:00.000Z', 'status' => ['label' => 'PART PAID', 'tone' => 'part'],
            'business' => ['name' => 'Acme (Lagos) \\ Ltd', 'phone' => '0803', 'email' => null, 'address' => '1 Market Rd', 'paymentDetails' => 'GTB 0123456789', 'footerNote' => 'Thank you!', 'logoDataUrl' => null],
            'customer' => ['name' => 'Ada Obi', 'phone' => '0803', 'email' => null, 'address' => null],
            'items' => [['name' => 'Rice 5kg', 'quantity' => 2, 'unitPrice' => '6500.00', 'total' => '13000.00'], ['name' => 'Haircut ₦ — "premium" ünïcode', 'quantity' => 1, 'unitPrice' => '1500.50', 'total' => '1500.50']],
            'subtotal' => '14500.50', 'discount' => '500.00', 'tax' => '1087.54', 'taxRate' => '7.5', 'total' => '15088.04', 'amountPaid' => '2000.00', 'balanceDue' => '13088.04', 'change' => '0.00',
            'paymentMethod' => 'On credit', 'currencyCode' => 'NGN', 'currencySymbol' => '₦', 'notes' => 'Deliver (Tuesday)', 'revisedAt' => null];
    }

    /** every xref offset must point at "N 0 obj" and startxref at "xref" — a PDF reader fails on anything else */
    private function assertWellFormed(string $pdf): int
    {
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
        $this->assertSame(1, preg_match('/startxref\n(\d+)\n%%EOF\n$/', $pdf, $m));
        $this->assertSame('xref', substr($pdf, (int) $m[1], 4));
        preg_match('/xref\n0 (\d+)\n((?:\d{10} \d{5} [nf] \n)+)trailer/', $pdf, $x);
        $count = (int) $x[1];
        $rows = explode("\n", rtrim($x[2], "\n"));
        $this->assertCount($count, $rows);
        foreach ($rows as $i => $row) {
            if ($i === 0) { continue; }
            $off = (int) substr($row, 0, 10);
            $this->assertStringStartsWith("$i 0 obj\n", substr($pdf, $off, 20), "object $i offset");
        }
        $this->assertSame(1, preg_match('/<< \/Length (\d+) >>\nstream\n/', $pdf, $l));
        return $count;
    }

    public function testProducesAWellFormedPdfWithEscapedLatin1Text(): void
    {
        $pdf = PdfRenderer::render($this->doc());
        $this->assertWellFormed($pdf);
        $this->assertStringContainsString('Acme \\(Lagos\\) \\\\ Ltd', $pdf, 'parentheses and backslashes are escaped');
        $this->assertStringContainsString('13,088.04', $pdf);
        $this->assertStringContainsString('NGN', $pdf);
        $this->assertStringNotContainsString('₦', $pdf, 'characters outside the PDF base font are not emitted raw');
        $this->assertStringContainsString("\xFC", $pdf, 'ü survives as one WinAnsi byte');
        $this->assertSame(1, preg_match_all('/\/Type \/Page /', $pdf), 'one page');
    }

    public function testLongDocumentsPaginateAndKeepEveryLine(): void
    {
        $items = [];
        for ($i = 1; $i <= 150; $i++) { $items[] = ['name' => "Item number $i with a fairly long descriptive name that must wrap onto a second line", 'quantity' => 1, 'unitPrice' => '1.00', 'total' => '1.00']; }
        $pdf = PdfRenderer::render($this->doc(['items' => $items]));
        $this->assertWellFormed($pdf);
        $pages = preg_match_all('/\/Type \/Page /', $pdf);
        $this->assertGreaterThan(3, $pages);
        $this->assertStringContainsString("Page 1 of $pages", $pdf);
        $this->assertStringContainsString('Item number 150', $pdf);
    }

    public function testControlCharactersAndHugeValuesCannotBreakTheFile(): void
    {
        $pdf = PdfRenderer::render($this->doc(['notes' => "line1\r\nline2\x00\x07 ) endstream endobj << /Evil true >>", 'total' => '99999999999.99', 'items' => []]));
        $this->assertWellFormed($pdf);
        $this->assertSame(1, substr_count($pdf, "\nendstream\n"), 'text cannot inject a second stream terminator');
        $this->assertStringContainsString('99,999,999,999.99', $pdf);
    }

    public function testRealPdfToolsCanReadTheOutput(): void
    {
        $bin = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));
        if ($bin === '') { $this->markTestSkipped('pdftotext not installed: structural checks above still ran.'); }
        $f = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($f, PdfRenderer::render($this->doc()));
        $text = (string) shell_exec(escapeshellcmd($bin) . ' -layout ' . escapeshellarg($f) . ' - 2>&1');
        unlink($f);
        $this->assertStringContainsString('INVOICE', $text);
        $this->assertStringContainsString('Rice 5kg', $text);
        $this->assertMatchesRegularExpression('/BALANCE DUE\s+NGN 13,088\.04/', $text);
        $this->assertStringContainsString('Acme (Lagos) \\ Ltd', $text);
    }
}
