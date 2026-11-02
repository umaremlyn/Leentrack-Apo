<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Request;
use LeenTrack\Core\Uuid;
use LeenTrack\Core\Validator;
use LeenTrack\Http\TenantContext;
use LeenTrack\Repositories\TenantRepository;

final class LocationService
{
    private const FIELDS = ['name', 'location_type', 'address', 'city', 'state', 'postal_code', 'country', 'phone', 'email', 'manager_name', 'timezone'];

    public function __construct(private readonly TenantRepository $repo, private readonly AuditService $audit)
    {
    }

    /** @return list<array<string,mixed>> outlet-restricted members only see their outlets */
    public function list(TenantContext $t): array
    {
        $t->require('locations.read');
        return array_map($this->view(...), $this->repo->locations($t->businessId, $t->outletIds));
    }

    /** @return array<string,mixed> */
    public function get(TenantContext $t, string $id): array
    {
        $t->require('locations.read');
        $l = $this->repo->location($t->businessId, $id);
        if ($l === null || !$t->canAccessOutlet($id)) { throw ApiException::notFound(); }
        return $this->view($l);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function create(TenantContext $t, array $body, Request $req): array
    {
        $t->require('locations.manage');
        $t->requireFeature('locations');       // free plan keeps its single default outlet only
        $t->requireWithinLimit('outlets', $this->repo->countActiveLocations($t->businessId));
        $f = $this->parse($body, true);
        $id = Uuid::v4();
        $this->repo->insertLocation(['id' => $id, 'business_id' => $t->businessId] + $f);
        $this->audit->log('location.create', $req, $t->userId, $t->businessId, 'location', $id, null, ['name' => $f['name']]);
        return $this->view($this->repo->location($t->businessId, $id) ?? []);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function update(TenantContext $t, string $id, array $body, Request $req): array
    {
        $t->require('locations.manage');
        $l = $this->repo->location($t->businessId, $id) ?? throw ApiException::notFound();
        $f = $this->parse($body, false, true);
        if (isset($f['is_active'])) {
            if ((int) $f['is_active'] === 0 && (int) $l['is_default'] === 1) { throw ApiException::conflict('default_location', 'The default outlet cannot be deactivated.'); }
            if ((int) $f['is_active'] === 1 && (int) $l['is_active'] === 0) { $t->requireWithinLimit('outlets', $this->repo->countActiveLocations($t->businessId)); }
        }
        $this->repo->updateLocation($t->businessId, $id, $f);
        $this->audit->log('location.update', $req, $t->userId, $t->businessId, 'location', $id, array_intersect_key($l, $f), $f);
        return $this->view($this->repo->location($t->businessId, $id) ?? []);
    }

    /** Outlets referenced by stock/sales later can never be hard-deleted: DELETE deactivates. */
    public function deactivate(TenantContext $t, string $id, Request $req): void
    {
        $t->require('locations.manage');
        $l = $this->repo->location($t->businessId, $id) ?? throw ApiException::notFound();
        if ((int) $l['is_default'] === 1) { throw ApiException::conflict('default_location', 'The default outlet cannot be removed.'); }
        $this->repo->updateLocation($t->businessId, $id, ['is_active' => 0]);
        $this->audit->log('location.deactivate', $req, $t->userId, $t->businessId, 'location', $id);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function parse(array $body, bool $create, bool $allowActive = false): array
    {
        $v = new Validator($body);
        $v->only(array_merge(self::FIELDS, $allowActive ? ['is_active'] : []));
        $f = [];
        if ($create || $v->has('name')) { $f['name'] = $v->string('name', 2, 150); }
        if ($v->has('location_type')) { $f['location_type'] = $v->enum('location_type', ['store', 'warehouse', 'kiosk', 'office']); }
        foreach (['address' => 300, 'city' => 100, 'state' => 100, 'postal_code' => 20, 'country' => 100, 'phone' => 40, 'manager_name' => 150] as $k => $max) {
            if ($v->has($k)) { $f[$k] = $v->string($k, 1, $max, false); }
        }
        if ($v->has('email')) { $f['email'] = $v->email('email', false); }
        if ($v->has('timezone')) {
            $tz = $v->string('timezone', 3, 64);
            if ($tz !== null && !in_array($tz, \DateTimeZone::listIdentifiers(), true)) { $v->addError('timezone', 'Unknown time zone.'); }
            $f['timezone'] = $tz;
        }
        if ($allowActive && $v->has('is_active')) { $b = $v->bool('is_active'); $f['is_active'] = $b === null ? null : (int) $b; }
        $v->done();
        return $f;
    }

    /** @param array<string,mixed> $l @return array<string,mixed> */
    private function view(array $l): array
    {
        unset($l['business_hours']);
        $l['is_active'] = (bool) $l['is_active'];
        $l['is_default'] = (bool) $l['is_default'];
        return $l;
    }
}
