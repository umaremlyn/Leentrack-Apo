<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Golden;

use LeenTrack\Core\Money;
use LeenTrack\Services\Documents\DocumentBuilder;
use LeenTrack\Services\Documents\DocumentModel;
use LeenTrack\Services\Sales\EditMath;
use PHPUnit\Framework\TestCase;

/**
 * G-EDIT-1 and G-DOC-1. The JSON files were produced by running the ORIGINAL lib/sales/edit-math.ts and
 * lib/documents/{model,build}.ts (tools/golden/gen_sales.mjs). PHP must reproduce them to the cent / character.
 */
final class SalesGoldenTest extends TestCase
{
    private static function m(float|int|string|null $n): Money
    {
        if ($n === null) { return Money::zero(); }
        return Money::parse(is_string($n) ? $n : (rtrim(rtrim(sprintf('%.6F', $n), '0'), '.') ?: '0'), true);
    }

    /** @return array<string,mixed> */
    private static function load(string $f): array
    {
        return json_decode((string) file_get_contents(__DIR__ . "/$f"), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testEditMathMatchesOriginalForEveryGoldenCase(): void
    {
        $g = self::load('edit_math.json');
        $this->assertGreaterThan(500, $g['count']);
        $diffs = [];
        foreach ($g['cases'] as $n => $c) {
            $i = $c['input'];
            try {
                $r = EditMath::compute(
                    array_map(fn ($l) => ['quantity' => $l['quantity'], 'unit_price' => self::m($l['unit_price'])], $i['lines']),
                    self::m($i['discount']),
                    ['subtotal' => self::m($i['old']['subtotal']), 'discount' => self::m($i['old']['discount']), 'tax' => self::m($i['old']['tax']), 'total' => self::m($i['old']['total'])],
                    self::m($i['paid']),
                );
            } catch (\OverflowException) {
                $diffs[] = "case $n overflow " . json_encode($i);
                continue;
            }
            $got = ['subtotal' => $r['subtotal'], 'discount' => $r['discount'], 'tax' => $r['tax'], 'total' => $r['total'], 'diff' => $r['diff'], 'extraDue' => $r['extraDue'], 'refundDue' => $r['refundDue']];
            foreach ($got as $k => $money) {
                if (abs((float) $money->toString() - (float) $c['output'][$k]) > 0.0000001) { $diffs[] = "case $n $k want {$c['output'][$k]} got {$money->toString()} for " . json_encode($i); }
            }
            if (($r['discountError'] !== '') !== ($c['output']['discountError'] !== '')) { $diffs[] = "case $n discountError"; }
        }
        $this->assertSame([], array_slice($diffs, 0, 8), count($diffs) . ' differences in total');
    }

    /** @param array<string,mixed> $s */
    private static function saleLike(array $s): array
    {
        $o = $s;
        foreach (['subtotal', 'discount', 'tax', 'total', 'amount_paid', 'balance_due', 'change'] as $k) {
            $o[$k] = array_key_exists($k, $s) && $s[$k] !== null ? self::m($s[$k]) : null;
            if ($o[$k] === null) { unset($o[$k]); }
        }
        $o['items'] = array_map(fn ($i) => ['name' => $i['name'], 'quantity' => $i['quantity'], 'price' => self::m($i['price']), 'total' => self::m($i['total'])], $s['items'] ?? []);
        return $o;
    }

    /** Recursively turns Money into floats so it can be compared with the JSON numbers. */
    private static function plain(mixed $v): mixed
    {
        if ($v instanceof Money) { return (float) $v->toString(); }
        if (is_array($v)) { return array_map(self::plain(...), $v); }
        return $v;
    }

    private static function ctx(array $c): array
    {
        return ['businessName' => $c['businessName'], 'branding' => $c['branding'] ?? null, 'currencyCode' => $c['currencyCode'] ?? null, 'currencySymbol' => $c['currencySymbol'] ?? null, 'taxRate' => isset($c['taxRate']) ? (string) $c['taxRate'] : null];
    }

    /** @param array<string,mixed> $want @param array<string,mixed> $got */
    private function assertDoc(array $want, array $got, string $label): void
    {
        $got = self::plain($got);
        if (isset($want['taxRate']) && $want['taxRate'] !== null) { $got['taxRate'] = (float) $got['taxRate']; }
        foreach ($want as $k => $v) {
            $this->assertEquals($v, $got[$k] ?? null, "$label field $k");
        }
    }

    public function testDocumentFromSaleMatchesOriginal(): void
    {
        $g = self::load('documents.json');
        foreach ($g['docs'] as $n => $c) {
            $got = DocumentBuilder::fromSale(self::saleLike($c['input']['sale']), self::ctx($c['input']['ctx']));
            $this->assertDoc($c['output'], $got, "doc $n");
        }
        $this->assertCount(10, $g['docs']);
    }

    public function testPaymentDocumentMatchesOriginal(): void
    {
        $g = self::load('documents.json');
        foreach ($g['payDocs'] as $n => $c) {
            $p = $c['input']['payment'];
            $got = DocumentBuilder::fromPayment(['reference' => $p['reference'], 'date' => $p['date'], 'customer' => $p['customer'], 'amount' => self::m($p['amount']), 'method' => $p['method'], 'previousBalance' => self::m($p['previousBalance']), 'newBalance' => self::m($p['newBalance'])], self::ctx($c['input']['ctx']));
            $this->assertDoc($c['output'], $got, "payment doc $n");
        }
    }

    public function testHelpersMatchOriginal(): void
    {
        $h = self::load('documents.json')['helpers'];
        foreach ($h['documentNumber'] as $c) { $this->assertSame($c['output'], DocumentModel::documentNumber($c['input'][0], $c['input'][1]), json_encode($c['input'])); }
        foreach ($h['paymentMethodLabel'] as $c) { $this->assertSame($c['output'], DocumentModel::paymentMethodLabel($c['input'][0], $c['input'][1]), json_encode($c['input'])); }
        foreach ($h['whatsappNumber'] as $c) { $this->assertSame($c['output'], DocumentModel::whatsappNumber($c['input']), json_encode($c['input'])); }
        foreach ($h['money'] as $c) { $this->assertSame($c['output'], DocumentModel::money(self::m($c['input'][0]), $c['input'][1], $c['input'][2]), json_encode($c['input'])); }
        foreach ($h['fileNameFor'] as $c) { $this->assertSame($c['output'], DocumentModel::fileNameFor($c['input']), json_encode($c['input'])); }
        foreach ($h['titleFor'] as $c) { $this->assertSame($c['output'], DocumentModel::titleFor($c['input'])); }
        foreach ($h['statusFor'] as $c) {
            $i = $c['input'];
            $this->assertSame($c['output'], DocumentModel::statusFor(['kind' => $i['kind'], 'balanceDue' => self::m($i['balanceDue']), 'amountPaid' => self::m($i['amountPaid'])]), json_encode($i));
        }
    }

    public function testShareMessagesMatchOriginalCharacterForCharacter(): void
    {
        $g = self::load('documents.json');
        $docs = [$g['docs'][0]['output'], $g['docs'][1]['output'], $g['payDocs'][0]['output'], $g['payDocs'][1]['output'], $g['docs'][2]['output']];
        foreach ($g['helpers']['shareMessage'] as $n => $c) {
            $d = $c['input'];
            foreach (['subtotal', 'discount', 'tax', 'total', 'amountPaid', 'balanceDue', 'change'] as $k) { $d[$k] = self::m($d[$k]); }
            if (isset($d['payment'])) { foreach (['amount', 'previousBalance', 'newBalance'] as $k) { $d['payment'][$k] = self::m($d['payment'][$k]); } }
            $this->assertSame($c['output'], DocumentModel::shareMessage($d), "share message $n");
        }
        $this->assertCount(5, $docs);
    }
}
