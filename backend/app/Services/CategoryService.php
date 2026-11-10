<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Request;
use LeenTrack\Core\Uuid;
use LeenTrack\Core\Validator;
use LeenTrack\Http\TenantContext;
use LeenTrack\Repositories\CatalogRepository;

final class CategoryService
{
    public function __construct(private readonly CatalogRepository $repo, private readonly AuditService $audit)
    {
    }

    /** @return list<array<string,mixed>> */
    public function list(TenantContext $t): array
    {
        $t->require('products.read');
        return array_map(fn ($c) => $c + [], $this->repo->categories($t->businessId));
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function create(TenantContext $t, array $body, Request $req): array
    {
        $t->require('categories.write');
        [$name, $desc] = $this->parse($body);
        $t->requireWithinLimit('categories', $this->repo->countCategories($t->businessId));
        $id = Uuid::v4();
        try {
            $this->repo->insertCategory($id, $t->businessId, $name, $desc);
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) { throw ApiException::conflict('duplicate_name', 'A category with this name already exists.'); }
            throw $e;
        }
        $this->audit->log('category.create', $req, $t->userId, $t->businessId, 'category', $id, null, ['name' => $name]);
        return $this->repo->category($t->businessId, $id) ?? [];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function update(TenantContext $t, string $id, array $body, Request $req): array
    {
        $t->require('categories.write');
        $c = $this->find($t, $id);
        [$name, $desc] = $this->parse($body);
        try {
            $this->repo->updateCategory($t->businessId, $id, $name, $desc);
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) { throw ApiException::conflict('duplicate_name', 'A category with this name already exists.'); }
            throw $e;
        }
        $this->audit->log('category.update', $req, $t->userId, $t->businessId, 'category', $id, ['name' => $c['name']], ['name' => $name]);
        return $this->repo->category($t->businessId, $id) ?? [];
    }

    /** Products in the category are kept and become uncategorised (same as the source). */
    public function delete(TenantContext $t, string $id, Request $req): void
    {
        $t->require('categories.write');
        $c = $this->find($t, $id);
        $this->repo->deleteCategory($t->businessId, $id);
        $this->audit->log('category.delete', $req, $t->userId, $t->businessId, 'category', $id, ['name' => $c['name']]);
    }

    /** @return array<string,mixed> */
    private function find(TenantContext $t, string $id): array
    {
        if (!Uuid::isValid($id)) { throw ApiException::notFound(); }
        return $this->repo->category($t->businessId, $id) ?? throw ApiException::notFound();
    }

    /** @param array<string,mixed> $body @return array{0:string,1:?string} */
    private function parse(array $body): array
    {
        $v = new Validator($body);
        $v->only(['name', 'description']);
        $name = $v->string('name', 1, 100);
        $desc = $v->string('description', 1, 300, false);
        $v->done();
        return [(string) $name, $desc];
    }
}
