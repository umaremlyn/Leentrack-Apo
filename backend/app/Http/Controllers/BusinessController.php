<?php
declare(strict_types=1);

namespace LeenTrack\Http\Controllers;

use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Repositories\TenantRepository;
use LeenTrack\Services\BusinessService;
use LeenTrack\Services\PlanCatalog;

final class BusinessController
{
    public function __construct(private readonly BusinessService $svc, private readonly TenantRepository $repo)
    {
    }

    public function index(Request $r): Response
    {
        $list = array_map(function ($m) { $m['effective_plan'] = PlanCatalog::effective($m); return $m; }, $this->repo->membershipsOf($r->auth()->userId));
        return Response::ok($list);
    }

    public function create(Request $r): Response { $a = $r->auth(); return Response::ok($this->svc->create($a->userId, $a->email, $r->json(), $r), [], 201); }
    public function show(Request $r): Response { return Response::ok($this->svc->get($r->tenant())); }
    public function update(Request $r): Response { return Response::ok($this->svc->update($r->tenant(), $r->json(), $r)); }
}
