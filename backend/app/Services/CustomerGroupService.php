<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Request;
use LeenTrack\Core\Uuid;
use LeenTrack\Core\Validator;
use LeenTrack\Http\TenantContext;
use LeenTrack\Repositories\CustomerRepository;

final class CustomerGroupService
{
    public function __construct(private readonly CustomerRepository $repo, private readonly AuditService $audit)
    {
    }

    /** @return list<array<string,mixed>> readable by every member: cashiers need the group discount at checkout */
    public function list(TenantContext $t): array
    {
        $t->require('groups.read');
        return array_map(fn ($g) => ['id' => $g['id'], 'name' => $g['name'], 'description' => $g['description'], 'discount_percentage' => $g['discount_percentage'], 'customer_count' => (int) $g['customer_count'], 'created_at' => $g['created_at']], $this->repo->groups($t->businessId));
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function create(TenantContext $t, array $body, Request $req): array
    {
        $t->require('groups.write');
        [$name, $desc, $pct] = $this->parse($body);
        $id = Uuid::v4();
        $this->repo->insertGroup($id, $t->businessId, $name, $desc, $pct);
        $this->audit->log('group.create', $req, $t->userId, $t->businessId, 'customer_group', $id, null, ['name' => $name, 'discount_percentage' => $pct]);
        return $this->repo->group($t->businessId, $id) ?? [];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function update(TenantContext $t, string $id, array $body, Request $req): array
    {
        $t->require('groups.write');
        $g = $this->find($t, $id);
        [$name, $desc, $pct] = $this->parse($body);
        $this->repo->updateGroup($t->businessId, $id, $name, $desc, $pct);
        $this->audit->log('group.update', $req, $t->userId, $t->businessId, 'customer_group', $id, ['name' => $g['name'], 'discount_percentage' => $g['discount_percentage']], ['name' => $name, 'discount_percentage' => $pct]);
        return $this->repo->group($t->businessId, $id) ?? [];
    }

    /** Members of the group simply become group-less (FK SET NULL); past sales keep the discount they were given. */
    public function delete(TenantContext $t, string $id, Request $req): void
    {
        $t->require('groups.write');
        $g = $this->find($t, $id);
        $this->repo->deleteGroup($t->businessId, $id);
        $this->audit->log('group.delete', $req, $t->userId, $t->businessId, 'customer_group', $id, ['name' => $g['name']]);
    }

    /** @return array<string,mixed> */
    private function find(TenantContext $t, string $id): array
    {
        if (!Uuid::isValid($id)) { throw ApiException::notFound(); }
        return $this->repo->group($t->businessId, $id) ?? throw ApiException::notFound();
    }

    /** @param array<string,mixed> $body @return array{0:string,1:?string,2:string} */
    private function parse(array $body): array
    {
        $v = new Validator($body);
        $v->only(['name', 'description', 'discount_percentage']);
        $name = $v->string('name', 1, 100);
        $desc = $v->string('description', 1, 300, false);
        $pct = $v->has('discount_percentage') ? $v->percent('discount_percentage') : '0';
        $v->done();
        return [(string) $name, $desc, (string) $pct];
    }
}
