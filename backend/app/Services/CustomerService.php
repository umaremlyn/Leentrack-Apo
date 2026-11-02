<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Clock;
use LeenTrack\Core\Pager;
use LeenTrack\Core\Request;
use LeenTrack\Core\Uuid;
use LeenTrack\Core\Validator;
use LeenTrack\Http\TenantContext;
use LeenTrack\Repositories\CustomerRepository;

final class CustomerService
{
    /** Visits at which the web UI shows its "loyal customer" star. */
    public const LOYAL_VISITS = 5;

    public function __construct(private readonly CustomerRepository $repo, private readonly AuditService $audit)
    {
    }

    /** @param array<string,mixed> $q @return array{rows:list<array<string,mixed>>,meta:array<string,int>} */
    public function list(TenantContext $t, array $q): array
    {
        $t->require('customers.read');
        $allowed = ['search', 'group_id', 'archived', 'sort', 'dir', 'page', 'per_page'];
        if ($extra = array_diff(array_keys($q), $allowed)) { throw ApiException::validation([(string) reset($extra) => ['Unknown filter.']]); }
        $f = ['search' => $this->str($q, 'search', 100), 'group_id' => $this->uuid($q, 'group_id'), 'archived' => false];
        if (isset($q['archived'])) {
            if (!in_array($q['archived'], ['0', '1', 'true', 'false'], true)) { throw ApiException::validation(['archived' => ['Must be true or false.']]); }
            $f['archived'] = in_array($q['archived'], ['1', 'true'], true);
            if ($f['archived']) { $t->require('customers.delete'); }
        }
        $sort = $this->oneOf($q, 'sort', ['name', 'created_at', 'total_spent', 'last_visit', 'visit_count', 'loyalty_points']) ?? 'name';
        $dir = $this->oneOf($q, 'dir', ['asc', 'desc']) ?? 'asc';
        $pg = Pager::fromQuery($q, 50);
        $r = $this->repo->customers($t->businessId, $f, $sort, $dir, $pg->perPage, $pg->offset());
        return ['rows' => array_map($this->view(...), $r['rows']), 'meta' => $pg->meta($r['total'])];
    }

    /** @return array<string,mixed> */
    public function get(TenantContext $t, string $id): array
    {
        $t->require('customers.read');
        return $this->view($this->find($t, $id));
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function create(TenantContext $t, array $body, Request $req): array
    {
        $t->require('customers.create');
        $d = $this->parse($t, $body, true);
        $t->requireWithinLimit('customers', $this->repo->countLive($t->businessId));
        $id = Uuid::v4();
        $this->repo->insert($d + ['id' => $id, 'business_id' => $t->businessId]);
        $this->audit->log('customer.create', $req, $t->userId, $t->businessId, 'customer', $id, null, ['name' => $d['name']]);
        return $this->view($this->find($t, $id));
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function update(TenantContext $t, string $id, array $body, Request $req): array
    {
        $t->require('customers.update');
        $before = $this->find($t, $id);
        $d = $this->parse($t, $body, false);
        $this->repo->update($t->businessId, $id, $d);
        $this->audit->log('customer.update', $req, $t->userId, $t->businessId, 'customer', $id, array_intersect_key($before, $d), $d);
        return $this->view($this->find($t, $id));
    }

    /**
     * Customers are archived, never destroyed: their sales, repayments and points ledger keep referencing them.
     * (From 3d on, a customer who still owes money cannot be archived.)
     */
    public function archive(TenantContext $t, string $id, Request $req): void
    {
        $t->require('customers.delete');
        $this->find($t, $id);
        $this->repo->setArchived($t->businessId, $id, true);
        $this->audit->log('customer.archive', $req, $t->userId, $t->businessId, 'customer', $id);
    }

    /** @return array<string,mixed> */
    public function restore(TenantContext $t, string $id, Request $req): array
    {
        $t->require('customers.delete');
        $c = $this->repo->customer($t->businessId, $id, true) ?? throw ApiException::notFound();
        if ($c['archived_at'] === null) { throw ApiException::conflict('not_archived', 'This customer is not archived.'); }
        $t->requireWithinLimit('customers', $this->repo->countLive($t->businessId));
        $this->repo->setArchived($t->businessId, $id, false);
        $this->audit->log('customer.restore', $req, $t->userId, $t->businessId, 'customer', $id);
        return $this->view($this->find($t, $id));
    }

    /** @param array<string,mixed> $q @return array{rows:list<array<string,mixed>>,meta:array<string,int>} */
    public function ledger(TenantContext $t, string $id, array $q): array
    {
        $t->require('customers.read');
        $this->find($t, $id);
        $pg = Pager::fromQuery($q, 50);
        $r = $this->repo->ledger($t->businessId, $id, $pg->perPage, $pg->offset());
        return ['rows' => $r['rows'], 'meta' => $pg->meta($r['total'])];
    }

    // ---------------------------------------------------------------------------------------------
    /** @return array<string,mixed> */
    private function find(TenantContext $t, string $id): array
    {
        if (!Uuid::isValid($id)) { throw ApiException::notFound(); }
        return $this->repo->customer($t->businessId, $id) ?? throw ApiException::notFound();
    }

    /**
     * Counters (loyalty_points, total_spent, visit_count, last_visit) are rejected by the whitelist: they belong to the sale/loyalty services.
     * @param array<string,mixed> $body @return array<string,mixed>
     */
    private function parse(TenantContext $t, array $body, bool $create): array
    {
        $v = new Validator($body);
        $v->only(['name', 'email', 'phone', 'address', 'notes', 'date_of_birth', 'group_id']);
        $d = [];
        $set = fn (string $k) => $create || $v->has($k);
        if ($set('name')) { $d['name'] = $v->string('name', 1, 150); }
        if ($set('email')) { $d['email'] = $v->email('email', false); }
        if ($set('phone')) {
            $p = $v->string('phone', 5, 32, false);
            if ($p !== null && !preg_match('/^\+?[0-9 ()\-]{5,32}$/', $p)) { $v->addError('phone', 'Must be a valid phone number.'); }
            $d['phone'] = $p;
        }
        if ($set('address')) { $d['address'] = $v->string('address', 1, 300, false); }
        if ($set('notes')) { $d['notes'] = $v->string('notes', 1, 1000, false); }
        if ($set('date_of_birth')) {
            $s = $v->string('date_of_birth', 10, 10, false);
            if ($s !== null) {
                $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $s, new \DateTimeZone('UTC'));
                if (!$dt || $dt->format('Y-m-d') !== $s || $dt > Clock::now() || (int) $dt->format('Y') < 1900) { $v->addError('date_of_birth', 'Must be a past date as YYYY-MM-DD.'); }
            }
            $d['date_of_birth'] = $s;
        }
        if ($set('group_id')) { $d['customer_group_id'] = $v->uuid('group_id', false); }
        $v->done();
        if (isset($d['customer_group_id']) && $this->repo->group($t->businessId, $d['customer_group_id']) === null) {
            throw ApiException::validation(['group_id' => ['Customer group not found.']]);
        }
        return $d;
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private function view(array $c): array
    {
        return [
            'id' => $c['id'], 'name' => $c['name'], 'email' => $c['email'], 'phone' => $c['phone'], 'address' => $c['address'],
            'date_of_birth' => $c['date_of_birth'], 'notes' => $c['notes'],
            'group_id' => $c['customer_group_id'],
            'group' => $c['customer_group_id'] === null ? null : ['id' => $c['customer_group_id'], 'name' => $c['group_name'], 'discount_percentage' => $c['group_discount']],
            'loyalty_points' => (int) $c['loyalty_points'], 'total_spent' => $c['total_spent'], 'visit_count' => (int) $c['visit_count'],
            'last_visit' => $c['last_visit'], 'is_loyal' => (int) $c['visit_count'] >= self::LOYAL_VISITS,
            'archived' => $c['archived_at'] !== null, 'created_at' => $c['created_at'], 'updated_at' => $c['updated_at'],
        ];
    }

    /** @param array<string,mixed> $q */
    private function str(array $q, string $k, int $max): ?string
    {
        if (!isset($q[$k])) { return null; }
        if (!is_string($q[$k]) || $q[$k] === '' || mb_strlen($q[$k]) > $max) { throw ApiException::validation([$k => ["Must be text up to $max characters."]]); }
        return $q[$k];
    }

    /** @param array<string,mixed> $q */
    private function uuid(array $q, string $k): ?string
    {
        if (!isset($q[$k])) { return null; }
        if (!is_string($q[$k]) || !Uuid::isValid($q[$k])) { throw ApiException::validation([$k => ['Must be a valid id.']]); }
        return $q[$k];
    }

    /** @param array<string,mixed> $q @param list<string> $allowed */
    private function oneOf(array $q, string $k, array $allowed): ?string
    {
        if (!isset($q[$k])) { return null; }
        if (!is_string($q[$k]) || !in_array($q[$k], $allowed, true)) { throw ApiException::validation([$k => ['Must be one of: ' . implode(', ', $allowed) . '.']]); }
        return $q[$k];
    }
}
