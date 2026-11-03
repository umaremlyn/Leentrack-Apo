<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Clock;
use LeenTrack\Core\Db;
use LeenTrack\Core\Money;
use LeenTrack\Core\Pager;
use LeenTrack\Core\Request;
use LeenTrack\Core\Uuid;
use LeenTrack\Core\Validator;
use LeenTrack\Http\TenantContext;
use LeenTrack\Repositories\CatalogRepository;
use LeenTrack\Repositories\CustomerRepository;
use LeenTrack\Repositories\SaleRepository;
use LeenTrack\Services\Documents\DocumentBuilder;
use LeenTrack\Services\Loyalty\LoyaltyMath;
use LeenTrack\Services\Sales\EditMath;
use PDO;

/**
 * Sales. The client never sends money: only item ids + quantities, the discount CHOICES and how the customer pays. Every figure
 * (prices, discounts, tax, totals, change, balance, receipt number) is computed here from the catalogue and the business rules.
 *
 * Lock order everywhere: customer row -> sale row -> product rows (ascending id) -> stock level rows. Never deviate (deadlocks).
 */
final class SaleService
{
    private const METHODS = ['cash', 'card', 'bank_transfer', 'mobile_money', 'other'];
    private const MAX_LINES = 200;
    private const MAX_QTY = 100_000;

    public function __construct(
        private readonly PDO $pdo,
        private readonly SaleRepository $sales,
        private readonly CatalogRepository $catalog,
        private readonly CustomerRepository $customers,
        private readonly StockService $stock,
        private readonly LoyaltyService $loyalty,
        private readonly AuditService $audit,
        private readonly bool $enforceSalesLimit = false,
    ) {
    }

    // ===================================================================================================== checkout
    /**
     * @param array<string,mixed> $body
     * @return array{sale:array<string,mixed>,replayed:bool}
     */
    public function checkout(TenantContext $t, array $body, ?string $idempotencyKey, Request $req): array
    {
        $t->require('sales.create');
        $in = $this->parseCheckout($t, $body, $idempotencyKey);
        $bid = $t->businessId;

        // Plan limit (the source only showed a banner and never blocked the till, so this is opt-in: see docs G-29).
        if ($this->enforceSalesLimit && $this->sales->countSales($bid) >= PlanCatalog::limit($t->plan, 'sales')) {
            throw new ApiException('plan_limit_reached', 'Sales record limit reached for your plan.', 402);
        }

        $replay = $this->replayOrNull($t, $in);
        if ($replay !== null) { return ['sale' => $replay, 'replayed' => true]; }

        try {
            $saleId = Db::transaction($this->pdo, fn () => $this->checkoutTx($t, $in, $req));
        } catch (\PDOException $e) {
            // Lost the race for the same client_operation_id: another request committed it first -> return that sale.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 && str_contains((string) $e->getMessage(), 'uq_sales_operation')) {
                $replay = $this->replayOrNull($t, $in);
                if ($replay !== null) { return ['sale' => $replay, 'replayed' => true]; }
            }
            throw $e;
        }
        return ['sale' => $this->detail($t, $saleId), 'replayed' => false];
    }

    /** @param array<string,mixed> $in */
    private function replayOrNull(TenantContext $t, array $in): ?array
    {
        $existing = $this->sales->findByOperation($t->businessId, $in['operation_id']);
        if ($existing === null) { return null; }
        if ($existing['request_hash'] !== $in['hash']) {
            throw new ApiException('idempotency_conflict', 'This operation id was already used for a different sale.', 409);
        }
        return $this->detail($t, (string) $existing['id']);
    }

    /** @param array<string,mixed> $in */
    private function checkoutTx(TenantContext $t, array $in, Request $req): string
    {
        $bid = $t->businessId;
        $now = Clock::sql();

        // 1. customer first (D-26)
        $cust = null;
        $custGroupPct = '0';
        if ($in['customer_id'] !== null) {
            $cust = $this->customers->lockCustomer($bid, $in['customer_id']);
            if ($cust === null || $cust['archived_at'] !== null) { throw ApiException::validation(['customer_id' => ['Customer not found.']]); }
            $full = $this->customers->customer($bid, $in['customer_id']);
            $custGroupPct = (string) ($full['group_discount'] ?? '0');
            if ($full['customer_group_id'] === null) { $custGroupPct = '0'; }
        }
        if ($in['mode'] === 'credit' && $cust === null) {
            throw new ApiException('customer_required', 'Please select a customer before recording a credit sale. You need to know who owes you.', 422);
        }

        // 2. products, ascending id, under lock; prices come from HERE, never from the client
        $lines = [];
        $subtotal = Money::zero();
        $ids = array_keys($in['items']);
        sort($ids);
        $locked = [];
        foreach ($ids as $pid) {
            $p = $this->catalog->lockProduct($bid, $pid);
            if ($p === null || $p['archived_at'] !== null) { throw ApiException::validation(['items' => ['One of the items is no longer available.']]); }
            if (!(int) $p['is_active']) { throw ApiException::validation(['items' => ["\"{$p['name']}\" is switched off in your products, so it cannot be sold."]]); }
            $locked[$pid] = $p;
        }
        $pos = 0;
        foreach ($in['order'] as $pid) {           // keep the cashier's line order for the receipt
            $p = $locked[$pid];
            $qty = $in['items'][$pid];
            $price = Money::parse($p['selling_price']);
            $line = $price->mulInt($qty);
            $subtotal = $subtotal->add($line);
            $lines[] = ['id' => Uuid::v4(), 'product_id' => $pid, 'name' => (string) $p['name'], 'type' => (string) $p['item_type'], 'qty' => $qty, 'price' => $price, 'total' => $line, 'cost' => Money::parse($p['cost_price']), 'pos' => $pos++];
        }

        // 3. discounts + tax + total
        $loy = $this->loyalty->settings($bid);
        $d = LoyaltyMath::checkoutDiscounts([
            'subtotal' => $subtotal, 'manual' => $in['manual'], 'groupPct' => $custGroupPct, 'groupOn' => $in['apply_group'] && $cust !== null,
            'loyaltyOn' => $loy['enabled'] && $cust !== null, 'customerPoints' => (int) ($cust['loyalty_points'] ?? 0),
            'pointValue' => $loy['point_value'], 'pointsWanted' => $in['points'],
        ]);
        if ($d['manualError'] !== '') { throw ApiException::validation(['discount.manual' => [$d['manualError']]]); }
        if ($in['points'] > 0) {
            if ($cust === null) { throw ApiException::validation(['discount.points_redeemed' => ['Choose the customer who is using the points.']]); }
            if (!$loy['enabled']) { throw new ApiException('loyalty_disabled', 'Loyalty points are switched off for this business.', 422); }
            if ($in['points'] > $d['maxPoints']) { throw ApiException::validation(['discount.points_redeemed' => ["At most {$d['maxPoints']} point(s) can be used on this sale."]]); }
        }
        $biz = $this->sales->businessInfo($bid) ?? throw ApiException::notFound();
        $taxable = $subtotal->sub($d['discount']);
        $tax = $taxable->percent($this->percentString((string) $biz['tax_rate']));
        $total = $taxable->add($tax);

        // 4. payment
        if ($in['mode'] === 'full') {
            if ($in['amount']->lt($total)) { throw ApiException::validation(['payment.amount' => ['Amount paid must be equal to or greater than the total.']]); }
            $paid = $total;
            $change = $in['amount']->sub($total);
            $status = 'completed';
            $method = $in['method'];
        } else {
            if ($in['amount']->gt($total)) { throw ApiException::validation(['payment.amount' => ['Amount paid now cannot exceed the total.']]); }
            $paid = $in['amount'];
            $change = Money::zero();
            $status = $total->sub($paid)->minor > 0 ? 'pending' : 'completed';
            $method = $paid->minor > 0 ? $in['method'] : 'other';
        }

        // 5. outlet + stock (per outlet; services never touch stock)
        $outlet = $in['outlet_id'];
        $saleId = Uuid::v4();

        // 6. claim the operation id BEFORE touching stock: a concurrent duplicate blocks here, then hits the unique key
        $this->sales->insertSale([
            'id' => $saleId, 'business_id' => $bid, 'outlet_id' => $outlet, 'user_id' => $t->userId, 'customer_id' => $in['customer_id'],
            'receipt_number' => 'PENDING-' . $saleId, 'subtotal' => $subtotal->toString(), 'discount_amount' => $d['discount']->toString(),
            'group_discount' => $d['groupDiscount']->toString(), 'manual_discount' => $d['manual']->toString(), 'points_discount' => $d['pointsValue']->toString(),
            'points_redeemed' => $d['pointsUsed'], 'tax_rate' => $this->percentString((string) $biz['tax_rate']) === '0' ? '0.00' : (string) $biz['tax_rate'],
            'tax_amount' => $tax->toString(), 'total_amount' => $total->toString(), 'amount_paid' => $paid->toString(), 'change_amount' => $change->toString(),
            'payment_method' => $method, 'payment_status' => $status, 'is_credit' => $in['mode'] === 'credit', 'notes' => $in['notes'],
            'client_operation_id' => $in['operation_id'], 'request_hash' => $in['hash'], 'client_created_at' => $in['client_created_at'],
        ]);

        foreach ($lines as $l) {
            $this->sales->insertItem(['id' => $l['id'], 'sale_id' => $saleId, 'business_id' => $bid, 'product_id' => $l['product_id'], 'product_name' => $l['name'], 'item_type' => $l['type'],
                'quantity' => $l['qty'], 'unit_price' => $l['price']->toString(), 'total_price' => $l['total']->toString(), 'unit_cost' => $l['cost']->toString(), 'position' => $l['pos']]);
        }
        foreach ($ids as $pid) {                                   // ascending ids = lock order
            $l = array_values(array_filter($lines, fn ($x) => $x['product_id'] === $pid))[0];
            if ($l['type'] === 'product') {
                $this->stock->applyDelta($bid, $pid, $outlet, -$in['items'][$pid], 'sale', 'sale', $saleId, null, $t->userId);
            }
        }

        // 7. customer statistics + loyalty (same transaction: all or nothing)
        if ($cust !== null) {
            $this->customers->adjustStats($bid, $in['customer_id'], $paid->toString(), 1, $now);
            $this->loyalty->apply($bid, $in['customer_id'], $saleId, $paid, 'Sale', $t->userId);
            if ($d['pointsUsed'] > 0) {
                $this->loyalty->redeem($bid, $in['customer_id'], $saleId, $d['pointsUsed'], $d['discount'], 'Used on sale', $t->userId);
            }
        }

        // 8. receipt number last (short lock on the counter)
        $seq = $this->sales->nextSequence($bid, 'sale');
        $this->sales->setReceiptNumber($bid, $saleId, sprintf('SALE-%s-%06d', gmdate('Ymd'), $seq));

        $this->audit->log('sale.create', $req, $t->userId, $bid, 'sale', $saleId, null, ['total' => $total->toString(), 'items' => count($lines), 'mode' => $in['mode'], 'customer_id' => $in['customer_id']]);
        return $saleId;
    }

    // ===================================================================================================== read
    /** @param array<string,mixed> $q @return array{rows:list<array<string,mixed>>,meta:array<string,mixed>} */
    public function list(TenantContext $t, array $q): array
    {
        $t->require('sales.read');
        $allowed = ['from', 'to', 'status', 'payment', 'customer_id', 'outlet_id', 'search', 'page', 'per_page'];
        if ($extra = array_diff(array_keys($q), $allowed)) { throw ApiException::validation([(string) reset($extra) => ['Unknown filter.']]); }
        $tz = new \DateTimeZone($this->sales->businessTimezone($t->businessId));
        $f = [
            'from' => $this->localDay($q, 'from', $tz, 0), 'to' => $this->localDay($q, 'to', $tz, 1),
            'status' => $this->oneOf($q, 'status', ['completed', 'pending', 'voided', 'refunded']),
            'payment' => $this->oneOf($q, 'payment', ['credit', 'cash', 'card', 'bank_transfer', 'mobile_money', 'other']),
            'customer_id' => $this->uuid($q, 'customer_id'), 'outlet_id' => $this->uuid($q, 'outlet_id'),
            'search' => isset($q['search']) && is_string($q['search']) && $q['search'] !== '' && mb_strlen($q['search']) <= 100 ? $q['search'] : (isset($q['search']) ? throw ApiException::validation(['search' => ['Must be text up to 100 characters.']]) : null),
        ];
        if ($f['outlet_id'] !== null && (!$t->canAccessOutlet($f['outlet_id']) || !$this->catalog->locationExists($t->businessId, $f['outlet_id']))) { throw ApiException::notFound('Outlet not found.'); }
        $pg = Pager::fromQuery($q, 50);
        $r = $this->sales->list($t->businessId, $f, $t->outletIds, $pg->perPage, $pg->offset());
        $rows = array_map(fn ($s) => [
            'id' => $s['id'], 'receipt_number' => $s['receipt_number'], 'created_at' => $this->iso($s['created_at']), 'customer_id' => $s['customer_id'], 'customer_name' => $s['customer_name'],
            'total_amount' => $s['total_amount'], 'amount_paid' => $s['amount_paid'], 'payment_method' => $s['payment_method'], 'payment_status' => $s['payment_status'], 'is_credit' => (bool) $s['is_credit'], 'outlet_id' => $s['outlet_id'],
        ], $r['rows']);
        $limit = PlanCatalog::limit($t->plan, 'sales');
        return ['rows' => $rows, 'meta' => $pg->meta($r['total']) + ['summary' => $r['summary'], 'sales_limit_reached' => $limit !== PlanCatalog::INF && $this->sales->countSales($t->businessId) >= $limit]];
    }

    /** @return array<string,mixed> */
    public function get(TenantContext $t, string $id): array
    {
        $t->require('sales.read');
        return $this->detail($t, $id);
    }

    /** @return array<string,mixed> */
    private function detail(TenantContext $t, string $id): array
    {
        if (!Uuid::isValid($id)) { throw ApiException::notFound(); }
        $s = $this->sales->sale($t->businessId, $id) ?? throw ApiException::notFound();
        if ($s['outlet_id'] !== null ? !$t->canAccessOutlet((string) $s['outlet_id']) : $t->outletIds !== null) { throw ApiException::notFound(); }
        $items = $this->sales->items($t->businessId, $id);
        $repaid = Money::parse($this->sales->repaidTotal($t->businessId, $id));
        $edit = $this->sales->editInfo($t->businessId, $id);
        $total = Money::parse($s['total_amount']);
        $upfront = Money::parse($s['amount_paid']);
        $open = $s['payment_status'] === 'pending';
        $paidSoFar = $s['payment_status'] === 'voided' ? $upfront->add($repaid) : ($open ? $upfront->add($repaid)->min($total) : $total);
        $balance = $open ? $total->sub($upfront)->sub($repaid) : Money::zero();
        if ($balance->isNegative()) { $balance = Money::zero(); }
        $soldExTax = Money::parse($s['subtotal'])->sub(Money::parse($s['discount_amount']));
        $out = [
            'id' => $s['id'], 'receipt_number' => $s['receipt_number'], 'status' => $s['payment_status'], 'created_at' => $this->iso($s['created_at']), 'updated_at' => $this->iso($s['updated_at']),
            'outlet_id' => $s['outlet_id'], 'outlet_name' => $s['outlet_name'], 'cashier_id' => $s['user_id'], 'cashier_name' => $s['cashier_name'],
            'customer' => $s['customer_id'] === null ? null : ['id' => $s['customer_id'], 'name' => $s['customer_name'], 'phone' => $s['customer_phone'], 'email' => $s['customer_email'], 'address' => $s['customer_address']],
            'items' => array_map(function ($i) use ($t) {
                $o = ['id' => $i['id'], 'product_id' => $i['product_id'], 'name' => $i['product_name'], 'item_type' => $i['item_type'], 'quantity' => (int) $i['quantity'], 'unit_price' => $i['unit_price'], 'total_price' => $i['total_price']];
                if ($t->can('products.cost.read')) { $o['unit_cost'] = $i['unit_cost']; }
                return $o;
            }, $items),
            'subtotal' => $s['subtotal'], 'discount_amount' => $s['discount_amount'], 'group_discount' => $s['group_discount'], 'manual_discount' => $s['manual_discount'], 'points_discount' => $s['points_discount'], 'points_redeemed' => (int) $s['points_redeemed'],
            'tax_rate' => $s['tax_rate'], 'tax_amount' => $s['tax_amount'], 'total_amount' => $s['total_amount'], 'amount_paid' => $s['amount_paid'], 'change_amount' => $s['change_amount'],
            'payment_method' => $s['payment_method'], 'is_credit' => (bool) $s['is_credit'], 'repaid' => $repaid->toString(), 'paid_so_far' => $paidSoFar->toString(), 'balance_due' => $balance->toString(),
            'notes' => $s['notes'], 'edit_count' => $edit['count'], 'last_edited_at' => $edit['last'] === null ? null : $this->iso($edit['last']),
            'can_edit' => $t->can('sales.edit') && in_array($s['payment_status'], ['pending', 'completed'], true) && $items !== [],
            'voided_at' => $s['voided_at'] === null ? null : $this->iso($s['voided_at']), 'void_reason' => $s['void_reason'],
        ];
        if ($t->can('profit.read') && $items !== []) {
            $cost = Money::zero();
            $missing = false;
            foreach ($items as $i) {
                $c = $i['unit_cost'] === null ? Money::zero() : Money::parse($i['unit_cost']);
                $cost = $cost->add($c->mulInt((int) $i['quantity']));
                if ($i['item_type'] !== 'service' && $c->minor <= 0) { $missing = true; }
            }
            $out['profit_info'] = ['sold' => $soldExTax->toString(), 'cost' => $cost->toString(), 'profit' => $soldExTax->sub($cost)->toString(), 'cost_missing' => $missing];
        }
        return $out;
    }

    /** Receipt / invoice document model (JSON). @return array<string,mixed> */
    public function document(TenantContext $t, string $id, string $format = 'json'): array
    {
        $t->require('sales.read');
        return $this->documentModel($t, $id);
    }

    /** The same document as a PDF (see Documents\PdfRenderer for its stated limits). @return array{0:string,1:string} [bytes, file name] */
    public function documentPdf(TenantContext $t, string $id): array
    {
        $t->require('sales.read');
        $doc = $this->documentModel($t, $id);
        return [\LeenTrack\Services\Documents\PdfRenderer::render($doc), (string) $doc['file_name']];
    }

    /** @return array<string,mixed> */
    private function documentModel(TenantContext $t, string $id): array
    {
        $d = $this->detail($t, $id);
        $biz = $this->sales->businessInfo($t->businessId) ?? throw ApiException::notFound();
        $m = fn (string $x) => Money::parse($x);
        $sale = [
            'sale_number' => $d['receipt_number'], 'date' => $d['created_at'],
            'customer' => $d['customer'], 'items' => array_map(fn ($i) => ['name' => $i['name'], 'price' => $m($i['unit_price']), 'quantity' => $i['quantity'], 'total' => $m($i['total_price'])], $d['items']),
            'subtotal' => $m($d['subtotal']), 'discount' => $m($d['discount_amount']), 'tax' => $m($d['tax_amount']), 'total' => $m($d['total_amount']),
            'payment_method' => $d['payment_method'], 'amount_paid' => $m($d['paid_so_far']), 'balance_due' => $m($d['balance_due']), 'change' => $m($d['change_amount']),
            'is_credit' => $d['is_credit'], 'payment_status' => $d['status'], 'notes' => $d['notes'], 'revised_at' => $d['edit_count'] > 0 ? $d['last_edited_at'] : null,
        ];
        $symbols = ['NGN' => '₦', 'GHS' => '₵', 'KES' => 'KSh', 'ZAR' => 'R', 'EGP' => 'EGP ', 'USD' => '$', 'GBP' => '£', 'EUR' => '€', 'XOF' => 'CFA'];
        $doc = DocumentBuilder::fromSale($sale, [
            'businessName' => (string) $biz['name'], 'branding' => $this->sales->branding($t->businessId),
            'currencyCode' => (string) $biz['currency'], 'currencySymbol' => $symbols[$biz['currency']] ?? '', 'taxRate' => (float) $biz['tax_rate'] > 0 ? rtrim(rtrim((string) $biz['tax_rate'], '0'), '.') : null,
        ]);
        return DocumentBuilder::toApi($doc);
    }

    // ===================================================================================================== edit (port of edit_sale)
    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function edit(TenantContext $t, string $saleId, array $body, Request $req): array
    {
        $t->require('sales.edit');
        if (!Uuid::isValid($saleId)) { throw ApiException::notFound(); }
        $v = new Validator($body);
        $v->only(['items', 'discount', 'extra_paid', 'extra_method', 'reason', 'expected_updated_at', 'expected_paid']);
        $discount = $v->has('discount') ? $v->money('discount', false, Money::zero()) : Money::zero();
        $extra = $v->has('extra_paid') ? $v->money('extra_paid', false, Money::zero()) : Money::zero();
        $method = $v->has('extra_method') ? $v->enum('extra_method', ['cash', 'card', 'transfer', 'bank_transfer', 'other'], false, 'cash') : 'cash';
        $reason = $v->string('reason', 1, 200, false);
        $expUpdated = $v->string('expected_updated_at', 10, 40, false);
        $expPaid = $v->has('expected_paid') ? $v->money('expected_paid', false) : null;
        $rawItems = $body['items'] ?? null;
        if (!is_array($rawItems) || $rawItems === [] || !array_is_list($rawItems)) { $v->addError('items', 'A sale needs at least one item.'); $rawItems = []; }
        if (count($rawItems) > self::MAX_LINES) { $v->addError('items', 'Too many items on one sale.'); $rawItems = []; }
        $newLines = [];
        foreach ($rawItems as $n => $it) {
            if (!is_array($it)) { $v->addError("items.$n", 'One of the items is not valid.'); continue; }
            $iv = new Validator($it);
            $iv->only(['product_id', 'quantity', 'unit_price']);
            $pid = $iv->uuid('product_id');
            $qty = $iv->int('quantity', 1, self::MAX_QTY);
            $price = $iv->money('unit_price', true);
            try { $iv->done(); } catch (ApiException $e) { foreach ($e->fields as $k => $msgs) { foreach ($msgs as $m) { $v->addError("items.$n.$k", $m); } } continue; }
            $newLines[] = ['product_id' => (string) $pid, 'quantity' => (int) $qty, 'unit_price' => $price];
        }
        $v->done();
        if ($method === 'transfer') { $method = 'bank_transfer'; }
        $bid = $t->businessId;

        $result = Db::transaction($this->pdo, function () use ($t, $saleId, $newLines, $discount, $extra, $method, $reason, $expUpdated, $expPaid, $bid) {
            $pre = $this->sales->saleCustomerId($bid, $saleId) ?? throw ApiException::notFound('Sale not found.');
            $customerId = $pre['customer_id'];
            if ($customerId !== null) { $this->customers->lockCustomer($bid, (string) $customerId); }          // 1. customer
            $sale = $this->sales->lockSale($bid, $saleId) ?? throw ApiException::notFound('Sale not found.');      // 2. sale
            if (($sale['customer_id'] ?? null) !== $customerId) { throw ApiException::conflict('stale_resource', 'This sale was just changed by someone else.'); }
            if ($sale['outlet_id'] !== null && !$t->canAccessOutlet((string) $sale['outlet_id'])) { throw ApiException::notFound('Sale not found.'); }
            if (!in_array($sale['payment_status'], ['pending', 'completed'], true)) { throw new ApiException('not_editable', 'This sale was cancelled or refunded, so it cannot be edited.', 422); }
            if ($expUpdated !== null && $this->ms($sale['updated_at']) !== $this->msIso($expUpdated)) {
                throw new ApiException('stale_resource', 'This sale was just changed by someone else. Please close this screen, open the sale again and retry.', 409);
            }
            $oldItems = $this->sales->items($bid, $saleId);
            if ($oldItems === []) { throw new ApiException('not_editable', 'This is an older sale without item details, so its items cannot be edited.', 422); }
            $oldTotal = Money::parse($sale['total_amount']);
            $oldSub = Money::parse($sale['subtotal']);
            $oldDisc = Money::parse($sale['discount_amount']);
            $oldTax = Money::parse($sale['tax_amount']);
            $sumItems = array_reduce($oldItems, fn (Money $c, $i) => $c->add(Money::parse($i['total_price'])), Money::zero());
            if (abs($sumItems->minor - $oldSub->minor) > 1) {
                throw new ApiException('not_editable', 'The items on this sale no longer add up to its total (an item may have been deleted), so it cannot be edited.', 422);
            }
            $repaid = Money::parse($this->sales->repaidTotal($bid, $saleId));
            $upfront = Money::parse($sale['amount_paid'])->min($oldTotal);
            if ($sale['payment_status'] === 'completed') {
                $upfront = $upfront->max($oldTotal->sub($repaid)->isNegative() ? Money::zero() : $oldTotal->sub($repaid))->min($oldTotal);
                $paidSoFar = $oldTotal;
            } else {
                $paidSoFar = $upfront->add($repaid)->min($oldTotal);
            }
            if ($expPaid !== null && abs($expPaid->minor - $paidSoFar->minor) > 0) {
                throw new ApiException('stale_resource', 'A payment was recorded on this sale meanwhile. Please close this screen, open the sale again and retry.', 409);
            }

            $subtotal = Money::zero();
            $rows = [];
            foreach ($newLines as $i => $l) {
                $line = $l['unit_price']->mulInt($l['quantity']);
                $subtotal = $subtotal->add($line);
                $rows[] = $l + ['total' => $line, 'pos' => $i];
            }
            if ($subtotal->minor > 100_000_000_000) { throw ApiException::validation(['items' => ['The total is too large.']]); }
            $calc = EditMath::compute(array_map(fn ($l) => ['quantity' => $l['quantity'], 'unit_price' => $l['unit_price']], $newLines), $discount, ['subtotal' => $oldSub, 'discount' => $oldDisc, 'tax' => $oldTax, 'total' => $oldTotal], $paidSoFar);
            if ($discount->gt(Money::zero()) && $calc['discountError'] !== '') { throw ApiException::validation(['discount' => [$calc['discountError']]]); }
            $total = $calc['total'];
            $diff = $total->sub($paidSoFar);

            // 3. products in ascending id (union of old and new), then stock deltas at the sale's outlet
            $outlet = $sale['outlet_id'] !== null ? (string) $sale['outlet_id'] : ($this->catalog->defaultLocationId($bid) ?? throw new ApiException('no_outlet', 'This business has no default outlet.', 409));
            $oldQ = [];
            foreach ($oldItems as $i) { $oldQ[$i['product_id']] = ($oldQ[$i['product_id']] ?? 0) + (int) $i['quantity']; }
            $newQ = [];
            foreach ($newLines as $l) { $newQ[$l['product_id']] = ($newQ[$l['product_id']] ?? 0) + $l['quantity']; }
            $pids = array_values(array_unique(array_merge(array_keys($oldQ), array_keys($newQ))));
            sort($pids);
            $prod = [];
            foreach ($pids as $pid) {
                $p = $this->catalog->lockProduct($bid, $pid);
                if ($p === null) { throw new ApiException('product_missing', 'One of the items is no longer in your product list.', 422); }
                if (($oldQ[$pid] ?? 0) === 0 && ($p['archived_at'] !== null || !(int) $p['is_active'])) {
                    throw new ApiException('product_unavailable', "\"{$p['name']}\" is switched off in your products, so it cannot be added.", 422);
                }
                $prod[$pid] = $p;
            }
            foreach ($pids as $pid) {
                $delta = ($newQ[$pid] ?? 0) - ($oldQ[$pid] ?? 0);
                if ($delta !== 0 && $prod[$pid]['item_type'] !== 'service') {
                    $this->stock->applyDelta($bid, $pid, $outlet, -$delta, 'sale_edit', 'sale', $saleId, null, $t->userId);
                }
            }

            // 4. money
            if ($extra->minor > 0 && $diff->minor <= 0) { throw ApiException::validation(['extra_paid' => ['No extra payment is needed for this change.']]); }
            if ($diff->minor > 0 && $extra->gt($diff)) { throw ApiException::validation(['extra_paid' => ['The extra payment is more than the customer needs to add.']]); }
            $refund = Money::zero();
            if ($diff->minor < 0) {
                $refund = Money::ofMinor(-$diff->minor);
                $newUpfront = $upfront->sub($refund);
                if ($newUpfront->isNegative()) { $newUpfront = Money::zero(); }
                $extra = Money::zero();
            } else {
                $newUpfront = $upfront->add($extra);
            }
            $balance = $total->sub($newUpfront->add($repaid));
            if ($balance->isNegative()) { $balance = Money::zero(); }
            if ($balance->minor > 0 && $customerId === null) {
                throw new ApiException('customer_required', 'This sale has no customer, so nothing can be left owing. Ask the customer to pay the extra now, or the sale must have a customer.', 422);
            }
            $status = $balance->minor > 0 ? 'pending' : 'completed';

            // 5. write
            $this->sales->deleteItems($bid, $saleId);
            foreach ($rows as $r) {
                $p = $prod[$r['product_id']];
                $this->sales->insertItem(['id' => Uuid::v4(), 'sale_id' => $saleId, 'business_id' => $bid, 'product_id' => $r['product_id'], 'product_name' => (string) $p['name'], 'item_type' => (string) $p['item_type'],
                    'quantity' => $r['quantity'], 'unit_price' => $r['unit_price']->toString(), 'total_price' => $r['total']->toString(), 'unit_cost' => $this->costFor($oldItems, $r['product_id'], $p), 'position' => $r['pos']]);
            }
            $pointsDisc = Money::parse($sale['points_discount']);
            if ($pointsDisc->gt($calc['discount'])) { $pointsDisc = $calc['discount']; }
            $this->sales->updateSale($bid, $saleId, [
                'subtotal' => $calc['subtotal']->toString(), 'discount_amount' => $calc['discount']->toString(), 'group_discount' => '0.00',
                'manual_discount' => $calc['discount']->sub($pointsDisc)->toString(), 'points_discount' => $pointsDisc->toString(),
                'tax_amount' => $calc['tax']->toString(), 'total_amount' => $total->toString(), 'amount_paid' => $newUpfront->toString(),
                'payment_status' => $status, 'is_credit' => ($balance->minor > 0 || (int) $sale['is_credit']) ? 1 : 0,
            ]);
            if ($customerId !== null) {
                $oldMoney = $upfront->min($oldTotal);
                $newMoney = $newUpfront->min($total);
                $delta = $newMoney->sub($oldMoney);
                if (!$delta->isZero()) {
                    $this->customers->adjustStats($bid, (string) $customerId, $delta->toString(), 0, null);
                    $this->loyalty->apply($bid, (string) $customerId, $saleId, $delta, 'Correction on sale ' . $sale['receipt_number'], $t->userId);
                }
            }
            $after = $this->sales->items($bid, $saleId);
            $this->sales->insertEdit([
                'id' => Uuid::v4(), 'business_id' => $bid, 'sale_id' => $saleId, 'edited_by' => $t->userId, 'reason' => $reason, 'old_total' => $oldTotal->toString(), 'new_total' => $total->toString(),
                'paid_so_far' => $paidSoFar->toString(), 'balance_due' => $balance->toString(), 'refund_due' => $refund->toString(), 'extra_paid' => $extra->toString(),
                'extra_method' => $extra->minor > 0 ? $method : null, 'before_discount' => $oldDisc->toString(), 'after_discount' => $calc['discount']->toString(),
                'before_items' => json_encode($this->snapshot($oldItems), JSON_UNESCAPED_UNICODE), 'after_items' => json_encode($this->snapshot($after), JSON_UNESCAPED_UNICODE),
            ]);
            return [
                'sale_id' => $saleId, 'items' => count($rows), 'old_total' => $oldTotal->toString(), 'subtotal' => $calc['subtotal']->toString(), 'discount' => $calc['discount']->toString(),
                'tax' => $calc['tax']->toString(), 'total' => $total->toString(), 'paid_so_far' => $paidSoFar->toString(), 'amount_paid' => $newUpfront->toString(), 'repaid' => $repaid->toString(),
                'balance_due' => $balance->toString(), 'refund_due' => $refund->toString(), 'extra_paid' => $extra->toString(), 'payment_status' => $status,
            ];
        });
        $this->audit->log('sale.edit', $req, $t->userId, $bid, 'sale', $saleId, ['total' => $result['old_total']], ['total' => $result['total'], 'refund_due' => $result['refund_due']]);
        return $result;
    }

    // ===================================================================================================== void
    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function void(TenantContext $t, string $saleId, array $body, Request $req): array
    {
        $t->require('sales.void');
        if (!Uuid::isValid($saleId)) { throw ApiException::notFound(); }
        $v = new Validator($body);
        $v->only(['reason']);
        $reason = $v->string('reason', 3, 200);
        $v->done();
        $bid = $t->businessId;
        $result = Db::transaction($this->pdo, function () use ($t, $saleId, $reason, $bid) {
            $pre = $this->sales->saleCustomerId($bid, $saleId) ?? throw ApiException::notFound('Sale not found.');
            $customerId = $pre['customer_id'];
            if ($customerId !== null) { $this->customers->lockCustomer($bid, (string) $customerId); }
            $sale = $this->sales->lockSale($bid, $saleId) ?? throw ApiException::notFound('Sale not found.');
            if ($sale['outlet_id'] !== null && !$t->canAccessOutlet((string) $sale['outlet_id'])) { throw ApiException::notFound('Sale not found.'); }
            if ($sale['payment_status'] === 'voided') { throw ApiException::conflict('already_voided', 'This sale is already voided.'); }
            if ($sale['payment_status'] === 'refunded') { throw ApiException::conflict('not_voidable', 'This sale was refunded.'); }
            $items = $this->sales->items($bid, $saleId);
            $outlet = $sale['outlet_id'] !== null ? (string) $sale['outlet_id'] : $this->catalog->defaultLocationId($bid);
            $byProduct = [];
            foreach ($items as $i) { if ($i['item_type'] === 'product') { $byProduct[$i['product_id']] = ($byProduct[$i['product_id']] ?? 0) + (int) $i['quantity']; } }
            ksort($byProduct);
            foreach ($byProduct as $pid => $qty) {
                $this->stock->applyDelta($bid, (string) $pid, (string) $outlet, $qty, 'sale_void', 'sale', $saleId, $reason, $t->userId);   // stock comes back
            }
            $repaid = Money::parse($this->sales->repaidTotal($bid, $saleId));
            $received = Money::parse($sale['amount_paid'])->min(Money::parse($sale['total_amount']))->add($repaid);
            if ($customerId !== null) {
                $this->customers->adjustStats($bid, (string) $customerId, '-' . $received->toString(), -1, null);
                $this->loyalty->apply($bid, (string) $customerId, $saleId, Money::ofMinor(-$received->minor), 'Sale ' . $sale['receipt_number'] . ' voided', $t->userId);
                if ((int) $sale['points_redeemed'] > 0) {
                    $this->loyalty->returnPoints($bid, (string) $customerId, $saleId, (int) $sale['points_redeemed'], 'Points returned: sale voided', $t->userId);
                }
            }
            $this->sales->updateSale($bid, $saleId, ['payment_status' => 'voided', 'voided_at' => Clock::sql(), 'voided_by' => $t->userId, 'void_reason' => $reason]);
            return ['sale_id' => $saleId, 'status' => 'voided', 'refund_due' => $received->toString(), 'stock_restored_items' => count($byProduct)];
        });
        $this->audit->log('sale.void', $req, $t->userId, $bid, 'sale', $saleId, null, ['reason' => $reason, 'refund_due' => $result['refund_due']]);
        return $result;
    }

    // ===================================================================================================== helpers
    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function parseCheckout(TenantContext $t, array $body, ?string $headerKey): array
    {
        $v = new Validator($body);
        $v->only(['client_operation_id', 'outlet_id', 'customer_id', 'items', 'discount', 'payment', 'notes', 'client_created_at']);
        $op = $v->has('client_operation_id') ? $v->uuid('client_operation_id') : null;
        if ($op === null && $headerKey !== null) {
            if (Uuid::isValid($headerKey)) { $op = $headerKey; } else { $v->addError('client_operation_id', 'Idempotency-Key must be a UUID.'); }
        }
        if ($op === null && !$v->has('client_operation_id')) { $v->addError('client_operation_id', 'This field is required (a UUID generated by the device, reused on retry).'); }
        $outlet = $v->uuid('outlet_id', false);
        $cust = $v->uuid('customer_id', false);
        $notes = $v->string('notes', 1, 500, false);
        $cca = null;
        if ($v->has('client_created_at')) {
            $s = $v->string('client_created_at', 10, 40, false);
            try { $dt = $s === null ? null : new \DateTimeImmutable($s); } catch (\Exception) { $dt = null; $v->addError('client_created_at', 'Must be an ISO-8601 timestamp.'); }
            if ($dt !== null) { $cca = Clock::sql($dt->setTimezone(new \DateTimeZone('UTC'))); }
        }

        $raw = $body['items'] ?? null;
        $items = [];
        $order = [];
        if (!is_array($raw) || $raw === [] || !array_is_list($raw)) { $v->addError('items', 'Add at least one item.'); }
        elseif (count($raw) > self::MAX_LINES) { $v->addError('items', 'Too many items on one sale.'); }
        else {
            foreach ($raw as $n => $it) {
                if (!is_array($it)) { $v->addError("items.$n", 'Not a valid item.'); continue; }
                $iv = new Validator($it);
                $iv->only(['product_id', 'quantity']);          // price / total / name are NOT accepted: the server decides them
                $pid = $iv->uuid('product_id');
                $q = $iv->int('quantity', 1, self::MAX_QTY);
                try { $iv->done(); } catch (ApiException $e) { foreach ($e->fields as $k => $msgs) { foreach ($msgs as $m) { $v->addError("items.$n.$k", $m); } } continue; }
                if (!isset($items[$pid])) { $order[] = (string) $pid; }
                $items[(string) $pid] = ($items[(string) $pid] ?? 0) + (int) $q;
                if ($items[(string) $pid] > self::MAX_QTY) { $v->addError("items.$n.quantity", 'Quantity is too large.'); }
            }
        }

        $manual = Money::zero();
        $apply = true;
        $points = 0;
        if (isset($body['discount'])) {
            if (!is_array($body['discount'])) { $v->addError('discount', 'Must be an object.'); }
            else {
                $dv = new Validator($body['discount']);
                $dv->only(['manual', 'apply_group', 'points_redeemed']);
                $manual = $dv->has('manual') ? ($dv->money('manual', false, Money::zero()) ?? Money::zero()) : Money::zero();
                $apply = $dv->has('apply_group') ? ($dv->bool('apply_group') ?? true) : true;
                $points = $dv->has('points_redeemed') ? ($dv->int('points_redeemed', 0, 10_000_000) ?? 0) : 0;
                try { $dv->done(); } catch (ApiException $e) { foreach ($e->fields as $k => $msgs) { foreach ($msgs as $m) { $v->addError("discount.$k", $m); } } }
            }
        }
        $mode = 'full'; $method = 'cash'; $amount = null;
        if (!isset($body['payment']) || !is_array($body['payment'])) { $v->addError('payment', 'Payment details are required.'); }
        else {
            $pv = new Validator($body['payment']);
            $pv->only(['mode', 'method', 'amount']);
            $mode = (string) $pv->enum('mode', ['full', 'credit']);
            $m = $pv->has('method') ? $pv->enum('method', array_merge(self::METHODS, ['transfer']), false, 'cash') : 'cash';
            $method = $m === 'transfer' ? 'bank_transfer' : (string) $m;
            $amount = $pv->money('amount', $mode === 'full', Money::zero());
            try { $pv->done(); } catch (ApiException $e) { foreach ($e->fields as $k => $msgs) { foreach ($msgs as $mm) { $v->addError("payment.$k", $mm); } } }
        }
        $v->done();

        // outlet: given, else the default one, else the only one a restricted member has
        if ($outlet === null) {
            $outlet = $this->catalog->defaultLocationId($t->businessId);
            if ($outlet === null || !$t->canAccessOutlet($outlet)) {
                $mine = $t->outletIds ?? [];
                $outlet = count($mine) === 1 ? $mine[0] : null;
            }
        }
        if ($outlet === null || !$this->catalog->locationBelongs($t->businessId, $outlet) || !$t->canAccessOutlet($outlet)) {
            throw ApiException::validation(['outlet_id' => ['Choose an outlet you can sell from.']]);
        }
        $sorted = $items;
        ksort($sorted);
        $hash = hash('sha256', json_encode([$outlet, $cust, $sorted, $order, $manual->toString(), $apply, $points, $mode, $method, $amount?->toString(), $notes], JSON_UNESCAPED_UNICODE));
        return ['operation_id' => (string) $op, 'outlet_id' => $outlet, 'customer_id' => $cust, 'items' => $items, 'order' => $order, 'manual' => $manual, 'apply_group' => $apply, 'points' => $points,
            'mode' => $mode, 'method' => $method, 'amount' => $amount ?? Money::zero(), 'notes' => $notes, 'client_created_at' => $cca, 'hash' => $hash];
    }

    private function percentString(string $dbDecimal): string
    {
        $p = rtrim(rtrim($dbDecimal, '0'), '.');
        return $p === '' ? '0' : $p;
    }

    /** @param list<array<string,mixed>> $old @param array<string,mixed> $p */
    private function costFor(array $old, string $productId, array $p): string
    {
        foreach ($old as $i) { if ($i['product_id'] === $productId && $i['unit_cost'] !== null) { return (string) $i['unit_cost']; } }   // keep the cost remembered at the original sale
        return (string) $p['cost_price'];
    }

    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    private function snapshot(array $items): array
    {
        return array_map(fn ($i) => ['product_id' => $i['product_id'], 'name' => $i['product_name'], 'quantity' => (int) $i['quantity'], 'unit_price' => $i['unit_price'], 'total_price' => $i['total_price']], $items);
    }

    private function iso(string $dbDatetime): string
    {
        return (new \DateTimeImmutable($dbDatetime, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    private function ms(string $dbDatetime): int
    {
        $d = new \DateTimeImmutable($dbDatetime, new \DateTimeZone('UTC'));
        return $d->getTimestamp() * 1000 + (int) $d->format('v');
    }

    private function msIso(string $iso): int
    {
        try { $d = new \DateTimeImmutable($iso); } catch (\Exception) { throw ApiException::validation(['expected_updated_at' => ['Must be an ISO-8601 timestamp.']]); }
        return $d->getTimestamp() * 1000 + (int) $d->format('v');
    }

    /** @param array<string,mixed> $q local calendar day (business time zone) -> UTC DATETIME; $plusDays=1 for an exclusive end */
    private function localDay(array $q, string $k, \DateTimeZone $tz, int $plusDays): ?string
    {
        if (!isset($q[$k])) { return null; }
        $d = is_string($q[$k]) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $q[$k], $tz) : false;
        if (!$d || $d->format('Y-m-d') !== $q[$k]) { throw ApiException::validation([$k => ['Must be a date as YYYY-MM-DD.']]); }
        return Clock::sql($d->modify("+$plusDays day")->setTimezone(new \DateTimeZone('UTC')));
    }

    /** @param array<string,mixed> $q @param list<string> $allowed */
    private function oneOf(array $q, string $k, array $allowed): ?string
    {
        if (!isset($q[$k])) { return null; }
        if (!is_string($q[$k]) || !in_array($q[$k], $allowed, true)) { throw ApiException::validation([$k => ['Must be one of: ' . implode(', ', $allowed) . '.']]); }
        return $q[$k];
    }

    /** @param array<string,mixed> $q */
    private function uuid(array $q, string $k): ?string
    {
        if (!isset($q[$k])) { return null; }
        if (!is_string($q[$k]) || !Uuid::isValid($q[$k])) { throw ApiException::validation([$k => ['Must be a valid id.']]); }
        return $q[$k];
    }
}
