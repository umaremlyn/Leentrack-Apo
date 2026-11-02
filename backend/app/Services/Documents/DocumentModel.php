<?php
declare(strict_types=1);

namespace LeenTrack\Services\Documents;

use LeenTrack\Core\Money;

/** Pure helpers for customer documents — port of lib/documents/model.ts. */
final class DocumentModel
{
    public static function titleFor(string $kind): string
    {
        return $kind === 'invoice' ? 'INVOICE' : ($kind === 'payment_receipt' ? 'PAYMENT RECEIPT' : 'RECEIPT');
    }

    /** "SALE-1792775102403-1800" -> "INV-1792775102403-1800" / "RCT-…" */
    public static function documentNumber(string $kind, ?string $reference): string
    {
        $ref = trim((string) $reference);
        $rest = preg_replace('/^(SALE|INV|RCT|RCP)-/i', '', $ref) ?? $ref;
        if ($rest === '') { $rest = $ref; }
        if ($rest === '') { $rest = '0000'; }
        return ($kind === 'invoice' ? 'INV' : 'RCT') . '-' . $rest;
    }

    /** @param array{kind:string,balanceDue:Money,amountPaid:Money} $d @return array{label:string,tone:string} */
    public static function statusFor(array $d): array
    {
        $owing = $d['balanceDue']->minor > 0 && $d['balanceDue']->gt(Money::zero()) && self::over005($d['balanceDue']);
        if ($d['kind'] === 'payment_receipt') {
            return $owing ? ['label' => 'PART PAYMENT', 'tone' => 'part'] : ['label' => 'FULLY PAID', 'tone' => 'paid'];
        }
        if ($owing) {
            return self::over005($d['amountPaid']) ? ['label' => 'PART PAID', 'tone' => 'part'] : ['label' => 'UNPAID', 'tone' => 'due'];
        }
        return ['label' => 'PAID', 'tone' => 'paid'];
    }

    /** the source's "> 0.005" test; in whole cents that means "at least one cent" */
    private static function over005(Money $m): bool
    {
        return $m->minor >= 1;
    }

    public static function paymentMethodLabel(?string $raw, bool $isCredit = false): string
    {
        $m = strtolower(trim((string) $raw));
        if ($isCredit && in_array($m, ['', 'credit', 'credit sale', 'other'], true)) { return 'On credit'; }
        return match ($m) {
            'cash' => 'Cash',
            'card' => 'Card',
            'transfer', 'bank_transfer' => 'Bank transfer',
            'credit', 'credit sale' => 'On credit',
            '' => 'Other',
            default => strtoupper(substr($m, 0, 1)) . str_replace('_', ' ', substr($m, 1)),
        };
    }

    /** Nigerian and international numbers -> digits for wa.me. Returns '' when unusable. */
    public static function whatsappNumber(?string $phone, string $defaultCountry = '234'): string
    {
        $d = preg_replace('/[^\d+]/', '', (string) $phone) ?? '';
        if ($d === '') { return ''; }
        if ($d[0] === '+') { $d = substr($d, 1); }
        elseif (str_starts_with($d, '00')) { $d = substr($d, 2); }
        elseif ($d[0] === '0') { $d = $defaultCountry . substr($d, 1); }
        elseif (strlen($d) <= 10) { $d = $defaultCountry . $d; }
        $d = preg_replace('/\D/', '', $d) ?? '';
        return strlen($d) >= 10 && strlen($d) <= 15 ? $d : '';
    }

    public static function money(Money $amount, string $symbol, string $code): string
    {
        $abs = abs($amount->minor);
        $body = number_format(intdiv($abs, 100) + 0, 0, '.', ',') . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
        $sym = $symbol !== '' ? $symbol : ($code === 'NGN' ? '₦' : $code . ' ');
        return ($amount->minor < 0 ? '-' : '') . $sym . $body;
    }

    /** @param array{kind:string,number:string,customer:?array{name?:?string}} $d */
    public static function fileNameFor(array $d): string
    {
        $label = $d['kind'] === 'invoice' ? 'Invoice' : ($d['kind'] === 'payment_receipt' ? 'Payment-Receipt' : 'Receipt');
        $name = (string) ($d['customer']['name'] ?? '');
        if (class_exists(\Normalizer::class)) { $name = \Normalizer::normalize($name, \Normalizer::FORM_KD) ?: $name; }
        $name = preg_replace('/[^A-Za-z0-9_\s-]/', '', $name) ?? '';
        $who = substr(preg_replace('/\s+/', '-', trim($name)) ?? '', 0, 24);
        $num = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $d['number']) ?? '', 0, 40);
        return "$label-$num" . ($who !== '' ? "-$who" : '') . '.pdf';
    }

    /** @param array<string,mixed> $d DocumentData with Money fields */
    public static function shareMessage(array $d): string
    {
        $m = fn (Money $n) => self::money($n, $d['currencySymbol'], $d['currencyCode']);
        $name = trim((string) ($d['customer']['name'] ?? ''));
        $biz = $d['business']['name'];
        $lines = [$name !== '' ? "Hello $name," : 'Hello,', ''];
        if ($d['kind'] === 'invoice') {
            array_push($lines, "Here is your invoice {$d['number']} from $biz.", 'Total: ' . $m($d['total']));
            if (self::over005($d['amountPaid'])) { $lines[] = 'Paid so far: ' . $m($d['amountPaid']); }
            $lines[] = 'Balance due: ' . $m($d['balanceDue']);
            if (trim((string) ($d['business']['paymentDetails'] ?? '')) !== '') { array_push($lines, '', 'How to pay:', trim($d['business']['paymentDetails'])); }
        } elseif ($d['kind'] === 'payment_receipt' && isset($d['payment'])) {
            $p = $d['payment'];
            array_push($lines, 'Thank you! We received your payment of ' . $m($p['amount']) . " at $biz.", "Receipt {$d['number']} (for invoice {$p['invoiceNumber']}).");
            $lines[] = self::over005($p['newBalance']) ? 'Remaining balance: ' . $m($p['newBalance']) : 'Your account is now fully paid. ✅';
        } else {
            array_push($lines, "Thank you for your purchase at $biz.", "Here is your receipt {$d['number']}.", 'Total paid: ' . $m($d['total']));
        }
        $footer = trim((string) ($d['business']['footerNote'] ?? ''));
        array_push($lines, '', $footer !== '' ? $footer : 'Thank you for your business!');
        return implode("\n", $lines);
    }
}
