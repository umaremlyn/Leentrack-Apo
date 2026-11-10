<?php
declare(strict_types=1);

namespace LeenTrack\Services\Documents;

use LeenTrack\Core\Money;

/** Port of lib/documents/build.ts: turns a sale (or a repayment) into DocumentData (money as Money objects). */
final class DocumentBuilder
{
    /**
     * @param array<string,mixed>|null $branding keys: logo_data, phone, email, address, payment_details, footer_note
     * @return array<string,mixed>
     */
    public static function business(string $name, ?array $branding): array
    {
        $b = $branding ?? [];
        $clean = fn ($s) => (is_string($s) && trim($s) !== '') ? trim($s) : null;
        return [
            'name' => trim($name) !== '' ? trim($name) : 'Your Business',
            'phone' => $clean($b['phone'] ?? null), 'email' => $clean($b['email'] ?? null), 'address' => $clean($b['address'] ?? null),
            'logoDataUrl' => $clean($b['logo_data'] ?? null), 'paymentDetails' => $clean($b['payment_details'] ?? null), 'footerNote' => $clean($b['footer_note'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $s SaleLike: sale_number,date,customer,items[{name,price:Money,quantity,total:Money}],subtotal,discount,tax,total,payment_method,amount_paid,balance_due,change,is_credit,payment_status,notes,revised_at (money = Money)
     * @param array{businessName:string,branding:?array,currencyCode?:?string,currencySymbol?:?string,taxRate?:?string} $ctx
     * @return array<string,mixed>
     */
    public static function fromSale(array $s, array $ctx): array
    {
        $z = Money::zero();
        $total = $s['total'] ?? $z;
        $paid = $s['amount_paid'] ?? (($s['is_credit'] ?? false) ? $z : $total);
        $balanceDue = $s['balance_due'] ?? null;
        $creditish = ($s['is_credit'] ?? false) || ($s['payment_status'] ?? null) === 'pending' || ($balanceDue !== null && $balanceDue->minor >= 1);
        $balance = $balanceDue !== null ? $balanceDue : ($creditish ? $total->sub($paid) : $z);
        if ($balance->isNegative()) { $balance = $z; }
        $kind = $balance->minor >= 1 ? 'invoice' : 'receipt';
        $c = $s['customer'] ?? null;
        $amountPaid = $kind === 'receipt' ? ($creditish ? $total : ($paid->minor > 0 ? $paid : $total)) : $paid;
        $change = $s['change'] ?? $z;
        return [
            'kind' => $kind,
            'number' => DocumentModel::documentNumber($kind, $s['sale_number'] ?? null),
            'date' => $s['date'],
            'business' => self::business($ctx['businessName'], $ctx['branding'] ?? null),
            'customer' => ($c !== null && !empty($c['name'])) ? ['name' => (string) $c['name'], 'phone' => $c['phone'] ?? null, 'email' => $c['email'] ?? null, 'address' => $c['address'] ?? null] : null,
            'items' => array_map(fn ($i) => ['name' => $i['name'], 'quantity' => $i['quantity'], 'unitPrice' => $i['price'], 'total' => $i['total']], $s['items'] ?? []),
            'subtotal' => $s['subtotal'] ?? $z, 'discount' => $s['discount'] ?? $z, 'tax' => $s['tax'] ?? $z, 'taxRate' => $ctx['taxRate'] ?? null,
            'total' => $total, 'amountPaid' => $amountPaid, 'balanceDue' => $balance, 'change' => $change->isNegative() ? $z : $change,
            'paymentMethod' => $creditish ? ($kind === 'invoice' ? 'On credit' : 'Credit sale, settled') : DocumentModel::paymentMethodLabel($s['payment_method'] ?? null),
            'currencyCode' => $ctx['currencyCode'] ?: 'NGN', 'currencySymbol' => $ctx['currencySymbol'] ?: '₦',
            'notes' => $s['notes'] ?? null, 'revisedAt' => $s['revised_at'] ?? null,
        ];
    }

    /**
     * @param array{reference:string,date:string,customer:array,amount:Money,method:string,previousBalance:Money,newBalance:Money} $p
     * @param array{businessName:string,branding:?array,currencyCode?:?string,currencySymbol?:?string} $ctx
     * @return array<string,mixed>
     */
    public static function fromPayment(array $p, array $ctx): array
    {
        $z = Money::zero();
        $invoiceNumber = DocumentModel::documentNumber('invoice', $p['reference']);
        $ts = (new \DateTimeImmutable($p['date']))->getTimestamp() * 1000 + (int) (new \DateTimeImmutable($p['date']))->format('v');
        $suffix = (int) (floor($ts / 1000 + 0.5)) % 100000;
        $newBal = $p['newBalance']->isNegative() ? $z : $p['newBalance'];
        return [
            'kind' => 'payment_receipt',
            'number' => DocumentModel::documentNumber('receipt', $p['reference'] . '-P' . $suffix),
            'date' => $p['date'],
            'business' => self::business($ctx['businessName'], $ctx['branding'] ?? null),
            'customer' => ['name' => $p['customer']['name'], 'phone' => $p['customer']['phone'] ?? null, 'email' => $p['customer']['email'] ?? null],
            'items' => [], 'subtotal' => $p['amount'], 'discount' => $z, 'tax' => $z, 'taxRate' => null, 'total' => $p['amount'], 'amountPaid' => $p['amount'],
            'balanceDue' => $newBal, 'change' => $z, 'paymentMethod' => DocumentModel::paymentMethodLabel($p['method']),
            'currencyCode' => $ctx['currencyCode'] ?: 'NGN', 'currencySymbol' => $ctx['currencySymbol'] ?: '₦',
            'payment' => ['amount' => $p['amount'], 'method' => DocumentModel::paymentMethodLabel($p['method']), 'previousBalance' => $p['previousBalance'], 'newBalance' => $newBal, 'invoiceNumber' => $invoiceNumber],
        ];
    }

    /** JSON-safe form for the API: Money -> "123.45". @param array<string,mixed> $d @return array<string,mixed> */
    public static function toApi(array $d): array
    {
        $conv = function ($v) use (&$conv) {
            if ($v instanceof Money) { return $v->toString(); }
            if (is_array($v)) { return array_map($conv, $v); }
            return $v;
        };
        $out = $conv($d);
        $out['title'] = DocumentModel::titleFor($d['kind']);
        $out['status'] = DocumentModel::statusFor($d);
        $out['file_name'] = DocumentModel::fileNameFor($d);
        $out['share_message'] = DocumentModel::shareMessage($d);
        $out['whatsapp_number'] = DocumentModel::whatsappNumber($d['customer']['phone'] ?? null);
        return $out;
    }
}
