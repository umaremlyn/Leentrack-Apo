<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Clock;
use LeenTrack\Core\Money;
use LeenTrack\Core\Pager;
use LeenTrack\Core\Request;
use LeenTrack\Core\Uuid;
use LeenTrack\Core\Validator;
use LeenTrack\Http\TenantContext;
use LeenTrack\Repositories\CatalogRepository;
use LeenTrack\Repositories\ExpenseRepository;

final class ExpenseService
{
    public const DEFAULT_CATEGORIES = ['Rent & Utilities', 'Salaries & Wages', 'Marketing & Advertising', 'Supplies & Materials', 'Equipment & Maintenance',
        'Transport & Logistics', 'Food & Entertainment', 'Professional Services', 'Insurance', 'Other'];
    private const METHODS = ['cash', 'bank_transfer', 'card', 'mobile_money', 'paystack', 'other'];

    public function __construct(private readonly ExpenseRepository $repo, private readonly CatalogRepository $catalog, private readonly AuditService $audit)
    {
    }

    // ================================================================================================ expenses
    /** @param array<string,mixed> $q @return array{rows:list<array<string,mixed>>,meta:array<string,mixed>} */
    public function list(TenantContext $t, array $q): array
    {
        $t->require('expenses.manage');
        $allowed = ['month', 'from', 'to', 'category_id', 'search', 'payment_method', 'outlet_id', 'page', 'per_page'];
        if ($extra = array_diff(array_keys($q), $allowed)) { throw ApiException::validation([(string) reset($extra) => ['Unknown filter.']]); }
        $from = $this->date($q, 'from');
        $to = isset($q['to']) ? $this->date($q, 'to', 1) : null;       // inclusive in the API, exclusive in SQL
        if (isset($q['month'])) {
            if (isset($q['from']) || isset($q['to'])) { throw ApiException::validation(['month' => ['Use either month or from/to.']]); }
            if (!is_string($q['month']) || !preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $q['month'], $m)) { throw ApiException::validation(['month' => ['Must be YYYY-MM.']]); }
            $first = new \DateTimeImmutable("{$m[1]}-{$m[2]}-01");
            $from = $first->format('Y-m-d');
            $to = $first->modify('+1 month')->format('Y-m-d');
        }
        $f = ['from' => $from, 'to' => $to, 'category_id' => $this->uuid($q, 'category_id'), 'payment_method' => $this->method($q, 'payment_method'), 'outlet_id' => $this->uuid($q, 'outlet_id'),
            'search' => isset($q['search']) ? (is_string($q['search']) && $q['search'] !== '' && mb_strlen($q['search']) <= 100 ? $q['search'] : throw ApiException::validation(['search' => ['Must be text up to 100 characters.']])) : null];
        if ($f['outlet_id'] !== null && !$t->canAccessOutlet($f['outlet_id'])) { throw ApiException::notFound('Outlet not found.'); }
        $pg = Pager::fromQuery($q, 50);
        $r = $this->repo->list($t->businessId, $f, $t->outletIds, $pg->perPage, $pg->offset());
        return ['rows' => array_map($this->view(...), $r['rows']), 'meta' => $pg->meta($r['total']) + ['summary' => [
            'count' => $r['total'], 'total' => $this->fmt($r['sum']),
            'by_category' => array_map(fn ($c) => ['category_id' => $c['category_id'], 'name' => $c['name'] ?? 'Uncategorised', 'count' => (int) $c['n'], 'total' => $this->fmt((string) $c['total'])], $r['byCategory']),
        ]]];
    }

    /** @return array<string,mixed> */
    public function get(TenantContext $t, string $id): array
    {
        $t->require('expenses.manage');
        return $this->view($this->find($t, $id));
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function create(TenantContext $t, array $body, Request $req): array
    {
        $t->require('expenses.manage');
        $d = $this->parse($t, $body, true);
        $id = Uuid::v4();
        $this->repo->insert($d + ['id' => $id, 'business_id' => $t->businessId, 'user_id' => $t->userId]);
        $this->audit->log('expense.create', $req, $t->userId, $t->businessId, 'expense', $id, null, ['amount' => $d['amount'], 'date' => $d['expense_date'], 'description' => $d['description']]);
        return $this->view($this->find($t, $id));
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function update(TenantContext $t, string $id, array $body, Request $req): array
    {
        $t->require('expenses.manage');
        $before = $this->find($t, $id);
        $d = $this->parse($t, $body, false);
        $this->repo->update($t->businessId, $id, $d);
        $this->audit->log('expense.update', $req, $t->userId, $t->businessId, 'expense', $id, array_intersect_key($before, $d), $d);
        return $this->view($this->find($t, $id));
    }

    /** Soft delete: the row is kept (and audited) because removing an expense silently rewrites past profit. */
    public function delete(TenantContext $t, string $id, Request $req): void
    {
        $t->require('expenses.manage');
        $e = $this->find($t, $id);
        $this->repo->softDelete($t->businessId, $id, $t->userId);
        $this->audit->log('expense.delete', $req, $t->userId, $t->businessId, 'expense', $id, ['amount' => $e['amount'], 'date' => $e['expense_date'], 'description' => $e['description']]);
    }

    // ================================================================================================ categories
    /** @return list<array<string,mixed>> */
    public function categories(TenantContext $t): array
    {
        $t->require('expenses.manage');
        return array_map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'description' => $c['description'], 'expense_count' => (int) $c['expense_count'], 'created_at' => $c['created_at']], $this->repo->categories($t->businessId));
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function createCategory(TenantContext $t, array $body, Request $req): array
    {
        $t->require('expenses.manage');
        [$name, $desc] = $this->parseCategory($body);
        $id = Uuid::v4();
        $this->guardDuplicate(fn () => $this->repo->insertCategory($id, $t->businessId, $name, $desc));
        $this->audit->log('expense_category.create', $req, $t->userId, $t->businessId, 'expense_category', $id, null, ['name' => $name]);
        return $this->repo->category($t->businessId, $id) ?? [];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function updateCategory(TenantContext $t, string $id, array $body, Request $req): array
    {
        $t->require('expenses.manage');
        $c = $this->findCategory($t, $id);
        [$name, $desc] = $this->parseCategory($body);
        $this->guardDuplicate(fn () => $this->repo->updateCategory($t->businessId, $id, $name, $desc));
        $this->audit->log('expense_category.update', $req, $t->userId, $t->businessId, 'expense_category', $id, ['name' => $c['name']], ['name' => $name]);
        return $this->repo->category($t->businessId, $id) ?? [];
    }

    /** Enforced on the server (the source only checked the rows already loaded in the browser). */
    public function deleteCategory(TenantContext $t, string $id, Request $req): void
    {
        $t->require('expenses.manage');
        $c = $this->findCategory($t, $id);
        $n = $this->repo->liveExpensesIn($t->businessId, $id);
        if ($n > 0) { throw ApiException::conflict('category_in_use', "This category is used by $n expense(s)."); }
        $this->repo->deleteCategory($t->businessId, $id);
        $this->audit->log('expense_category.delete', $req, $t->userId, $t->businessId, 'expense_category', $id, ['name' => $c['name']]);
    }

    /** The source's "add the default categories" button: only when the business has none. @return list<array<string,mixed>> */
    public function seedDefaults(TenantContext $t, Request $req): array
    {
        $t->require('expenses.manage');
        if ($this->repo->countCategories($t->businessId) > 0) { throw ApiException::conflict('already_has_categories', 'This business already has expense categories.'); }
        foreach (self::DEFAULT_CATEGORIES as $name) { $this->repo->insertCategory(Uuid::v4(), $t->businessId, $name, null); }
        $this->audit->log('expense_category.seed', $req, $t->userId, $t->businessId, 'business', $t->businessId, null, ['count' => count(self::DEFAULT_CATEGORIES)]);
        return $this->categories($t);
    }

    // ================================================================================================ helpers
    /** @return array<string,mixed> */
    private function find(TenantContext $t, string $id): array
    {
        if (!Uuid::isValid($id)) { throw ApiException::notFound(); }
        $e = $this->repo->expense($t->businessId, $id) ?? throw ApiException::notFound();
        if ($t->outletIds !== null && ($e['outlet_id'] === null || !in_array($e['outlet_id'], $t->outletIds, true))) { throw ApiException::notFound(); }
        return $e;
    }

    /** @return array<string,mixed> */
    private function findCategory(TenantContext $t, string $id): array
    {
        if (!Uuid::isValid($id)) { throw ApiException::notFound(); }
        return $this->repo->category($t->businessId, $id) ?? throw ApiException::notFound();
    }

    private function guardDuplicate(callable $fn): void
    {
        try { $fn(); } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) { throw ApiException::conflict('duplicate_name', 'This category already exists.'); }
            throw $e;
        }
    }

    /** @param array<string,mixed> $body @return array{0:string,1:?string} */
    private function parseCategory(array $body): array
    {
        $v = new Validator($body);
        $v->only(['name', 'description']);
        $name = $v->string('name', 1, 100);
        $desc = $v->string('description', 1, 300, false);
        $v->done();
        return [(string) $name, $desc];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function parse(TenantContext $t, array $body, bool $create): array
    {
        $v = new Validator($body);
        $v->only(['description', 'amount', 'category_id', 'payment_method', 'payment_status', 'payment_reference', 'expense_date', 'notes', 'outlet_id', 'receipt_image_url']);
        $d = [];
        $set = fn (string $k) => $create || $v->has($k);
        if ($set('description')) { $d['description'] = $v->string('description', 1, 300); }
        if ($set('amount')) {
            $a = $v->money('amount', true);
            if ($a !== null && $a->isZero()) { $v->addError('amount', 'Enter a valid amount greater than 0.'); }
            $d['amount'] = $a?->toString();
        }
        if ($set('category_id')) { $d['category_id'] = $v->uuid('category_id', false); }
        if ($set('payment_method')) {
            $m = $v->enum('payment_method', array_merge(self::METHODS, ['transfer']), false, 'cash');
            $d['payment_method'] = $m === 'transfer' ? 'bank_transfer' : $m;
        }
        if ($set('payment_status')) { $d['payment_status'] = $v->enum('payment_status', ['paid', 'pending'], false, 'paid'); }
        if ($set('payment_reference')) { $d['payment_reference'] = $v->string('payment_reference', 1, 100, false); }
        if ($set('notes')) { $d['notes'] = $v->string('notes', 1, 1000, false); }
        if ($set('receipt_image_url')) { $d['receipt_image_url'] = $v->url('receipt_image_url'); }
        if ($set('outlet_id')) { $d['outlet_id'] = $v->uuid('outlet_id', false); }
        if ($set('expense_date')) {
            $s = $v->string('expense_date', 10, 10);
            if ($s !== null) {
                $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $s, new \DateTimeZone('UTC'));
                if (!$dt || $dt->format('Y-m-d') !== $s) { $v->addError('expense_date', 'Date is required (YYYY-MM-DD).'); }
                elseif ($dt < new \DateTimeImmutable('2000-01-01') || $dt > Clock::now()->modify('+366 days')) { $v->addError('expense_date', 'That date is out of range.'); }
            }
            $d['expense_date'] = $s;
        }
        $v->done();
        if (($d['category_id'] ?? null) !== null && $this->repo->category($t->businessId, $d['category_id']) === null) { throw ApiException::validation(['category_id' => ['Category not found.']]); }
        if ($create && ($d['outlet_id'] ?? null) === null && $t->outletIds !== null) {
            $d['outlet_id'] = count($t->outletIds) === 1 ? $t->outletIds[0] : throw ApiException::validation(['outlet_id' => ['Choose the outlet this expense belongs to.']]);
        }
        if (($d['outlet_id'] ?? null) !== null && (!$this->catalog->locationBelongs($t->businessId, $d['outlet_id']) || !$t->canAccessOutlet($d['outlet_id']))) {
            throw ApiException::validation(['outlet_id' => ['Choose an outlet you can use.']]);
        }
        if ($create) { $d += ['category_id' => null, 'outlet_id' => null, 'notes' => null, 'payment_reference' => null, 'receipt_image_url' => null, 'payment_method' => 'cash', 'payment_status' => 'paid']; }
        return $d;
    }

    /** @param array<string,mixed> $e @return array<string,mixed> */
    private function view(array $e): array
    {
        return [
            'id' => $e['id'], 'description' => $e['description'], 'amount' => $e['amount'], 'category_id' => $e['category_id'], 'category_name' => $e['category_name'],
            'payment_method' => $e['payment_method'], 'payment_status' => $e['payment_status'], 'payment_reference' => $e['payment_reference'], 'expense_date' => $e['expense_date'],
            'notes' => $e['notes'], 'outlet_id' => $e['outlet_id'], 'receipt_image_url' => $e['receipt_image_url'], 'user_id' => $e['user_id'],
            'created_at' => (new \DateTimeImmutable($e['created_at'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    private function fmt(string $decimal): string
    {
        return preg_match('/^(-?\d+)(?:\.(\d+))?$/', $decimal, $m) ? $m[1] . '.' . str_pad(substr($m[2] ?? '', 0, 2), 2, '0') : '0.00';
    }

    /** @param array<string,mixed> $q */
    private function date(array $q, string $k, int $plusDays = 0): ?string
    {
        if (!isset($q[$k])) { return null; }
        $d = is_string($q[$k]) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $q[$k], new \DateTimeZone('UTC')) : false;
        if (!$d || $d->format('Y-m-d') !== $q[$k]) { throw ApiException::validation([$k => ['Must be a date as YYYY-MM-DD.']]); }
        return $d->modify("+$plusDays day")->format('Y-m-d');
    }

    /** @param array<string,mixed> $q */
    private function uuid(array $q, string $k): ?string
    {
        if (!isset($q[$k])) { return null; }
        if (!is_string($q[$k]) || !Uuid::isValid($q[$k])) { throw ApiException::validation([$k => ['Must be a valid id.']]); }
        return $q[$k];
    }

    /** @param array<string,mixed> $q */
    private function method(array $q, string $k): ?string
    {
        if (!isset($q[$k])) { return null; }
        $m = $q[$k] === 'transfer' ? 'bank_transfer' : $q[$k];
        if (!is_string($m) || !in_array($m, self::METHODS, true)) { throw ApiException::validation([$k => ['Unknown payment method.']]); }
        return $m;
    }
}
