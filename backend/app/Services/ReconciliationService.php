<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use PDO;

/**
 * Nightly read-only audit of the books. It never "fixes" anything: it finds drift (a bug, a manual edit in the database, a half-applied
 * restore) and reports WHICH records, so a person decides. Application code already keeps these invariants inside transactions; this is the
 * independent second check that also stands in for database triggers, which shared hosting often does not allow (docs G-35).
 * Output carries ids and counts only: no names, no amounts per customer.
 */
final class ReconciliationService
{
    private const SAMPLE = 5;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{checks:list<array{name:string,description:string,violations:int,samples:list<string>}>,findings:int} */
    public function run(): array
    {
        $checks = [];
        foreach ($this->definitions() as $name => [$desc, $sql]) {
            $rows = $this->pdo->query($sql . ' LIMIT 1000')->fetchAll(PDO::FETCH_COLUMN);
            $count = count($rows);
            if ($count === 1000) {                                       // bigger than the cap: count the rest exactly
                $count = (int) $this->pdo->query('SELECT COUNT(*) FROM (' . $sql . ') x')->fetchColumn();
            }
            $checks[] = ['name' => $name, 'description' => $desc, 'violations' => $count, 'samples' => array_map('strval', array_slice($rows, 0, self::SAMPLE))];
        }
        return ['checks' => $checks, 'findings' => array_sum(array_column($checks, 'violations'))];
    }

    /** @return array<string,array{0:string,1:string}> name => [description, SQL returning one id per violation] */
    private function definitions(): array
    {
        return [
            'stock_total_equals_levels' => ['products.stock_quantity must equal the sum of its outlet levels',
                'SELECT p.id FROM products p LEFT JOIN stock_levels l ON l.product_id = p.id GROUP BY p.id, p.stock_quantity HAVING p.stock_quantity <> COALESCE(SUM(l.quantity),0)'],
            'stock_levels_equal_movements' => ['each outlet level must equal the sum of its stock movements',
                'SELECT l.product_id FROM stock_levels l LEFT JOIN (SELECT product_id, location_id, SUM(delta) d FROM stock_movements GROUP BY product_id, location_id) m ON m.product_id = l.product_id AND m.location_id = l.location_id WHERE l.quantity <> COALESCE(m.d,0)'],
            'stock_never_negative' => ['no outlet may hold negative stock', 'SELECT product_id FROM stock_levels WHERE quantity < 0'],
            'customer_spend_equals_money_received' => ['customers.total_spent must equal money actually received (checkout payments + repayments) on non-voided sales',
                "SELECT c.id FROM customers c WHERE c.total_spent <> (SELECT COALESCE(SUM(LEAST(s.amount_paid, s.total_amount)),0) FROM sales s WHERE s.customer_id = c.id AND s.payment_status IN ('pending','completed'))
                    + (SELECT COALESCE(SUM(d.amount),0) FROM debt_repayments d JOIN sales s ON s.id = d.sale_id WHERE s.customer_id = c.id AND s.payment_status IN ('pending','completed'))"],
            'loyalty_points_equal_ledger' => ['customers.loyalty_points must equal the sum of their loyalty ledger',
                'SELECT c.id FROM customers c WHERE c.loyalty_points <> (SELECT COALESCE(SUM(points),0) FROM loyalty_ledger WHERE customer_id = c.id)'],
            'pending_sales_owe_money' => ['a sale still marked pending must have a balance of at least one cent',
                "SELECT s.id FROM sales s WHERE s.payment_status = 'pending' AND s.total_amount - s.amount_paid - COALESCE((SELECT SUM(amount) FROM debt_repayments WHERE sale_id = s.id),0) < 0.01"],
            'completed_credit_sales_are_settled' => ['a completed credit sale must be fully paid',
                "SELECT s.id FROM sales s WHERE s.payment_status = 'completed' AND s.is_credit = 1 AND s.total_amount - s.amount_paid - COALESCE((SELECT SUM(amount) FROM debt_repayments WHERE sale_id = s.id),0) >= 0.01"],
            'repayments_never_exceed_debt' => ['repayments on a sale can never exceed what was owed after the upfront payment',
                'SELECT s.id FROM sales s JOIN debt_repayments d ON d.sale_id = s.id GROUP BY s.id, s.total_amount, s.amount_paid HAVING SUM(d.amount) > s.total_amount - s.amount_paid + 0.005'],
            'sale_totals_add_up' => ['a sale total must equal its item lines + tax - discount',
                'SELECT s.id FROM sales s WHERE s.subtotal <> COALESCE((SELECT SUM(total_price) FROM sale_items WHERE sale_id = s.id), s.subtotal) OR ABS(s.total_amount - (s.subtotal - s.discount_amount + s.tax_amount)) > 0.005'],
            'purchase_received_equals_receipts' => ['received quantity on an order line must equal the sum of its delivery receipts',
                'SELECT i.id FROM purchase_order_items i LEFT JOIN (SELECT item_id, SUM(quantity) q FROM purchase_receipt_items GROUP BY item_id) r ON r.item_id = i.id WHERE i.quantity_received <> COALESCE(r.q,0)'],
            'purchase_status_matches_lines' => ['a received order has every line complete; an order with nothing received is not "received" or "partially_received"',
                "SELECT o.id FROM purchase_orders o WHERE (o.status = 'received' AND EXISTS (SELECT 1 FROM purchase_order_items i WHERE i.purchase_order_id = o.id AND i.quantity_received < i.quantity_ordered))
                    OR (o.status IN ('received','partially_received') AND NOT EXISTS (SELECT 1 FROM purchase_order_items i WHERE i.purchase_order_id = o.id AND i.quantity_received > 0))"],
            'supplier_payments_within_received' => ['payments to a supplier on an order can never exceed the goods received on it',
                'SELECT o.id FROM purchase_orders o JOIN supplier_payments p ON p.purchase_order_id = o.id GROUP BY o.id HAVING SUM(p.amount) > COALESCE((SELECT SUM(i.quantity_received * i.unit_cost) FROM purchase_order_items i WHERE i.purchase_order_id = o.id),0) + 0.005'],
            'transfer_quantities_consistent' => ['transfer lines: received <= shipped <= requested; a completed transfer has every line received',
                "SELECT t.id FROM transfers t WHERE EXISTS (SELECT 1 FROM transfer_items i WHERE i.transfer_id = t.id AND ((i.quantity_shipped IS NOT NULL AND i.quantity_shipped > i.quantity_requested) OR (i.quantity_received IS NOT NULL AND i.quantity_received > COALESCE(i.quantity_shipped,0))))
                    OR (t.status = 'completed' AND EXISTS (SELECT 1 FROM transfer_items i WHERE i.transfer_id = t.id AND i.quantity_received IS NULL))"],
            'paid_plans_have_an_expiry' => ["every business on a paid plan must have an expiry date (otherwise it would never end)",
                "SELECT id FROM businesses WHERE deleted_at IS NULL AND plan <> 'free' AND plan_expiry IS NULL"],
            'successful_payments_are_complete' => ['a successful subscription payment must record its paid time and access period',
                "SELECT id FROM payments WHERE status = 'success' AND (paid_at IS NULL OR period_start IS NULL OR period_end IS NULL OR period_end <= period_start)"],
            'stale_pending_payments' => ['subscription payments left pending for more than 3 days (the customer never finished, or a confirmation was missed)',
                "SELECT id FROM payments WHERE status = 'pending' AND created_at < (UTC_TIMESTAMP(3) - INTERVAL 3 DAY)"],
            'sync_operations_waiting_for_a_person' => ['offline operations that conflict or were rejected and have waited more than 7 days for a manager (sales that happened but are not in the books)',
                "SELECT client_operation_id FROM sync_operations WHERE status IN ('conflict','rejected') AND received_at < (UTC_TIMESTAMP(3) - INTERVAL 7 DAY)"],
            'accounts_pending_deletion_overdue' => ['accounts whose deletion date passed more than 2 days ago (is the purge job running?)',
                "SELECT id FROM users WHERE status = 'pending_deletion' AND anonymised_at IS NULL AND deletion_scheduled_at < (UTC_TIMESTAMP(3) - INTERVAL 2 DAY)"],
        ];
    }
}
