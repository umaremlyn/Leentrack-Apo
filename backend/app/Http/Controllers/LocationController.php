<?php
declare(strict_types=1);

namespace LeenTrack\Http\Controllers;

use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Services\LocationService;

final class LocationController
{
    public function __construct(private readonly LocationService $svc)
    {
    }

    public function index(Request $r): Response { return Response::ok($this->svc->list($r->tenant())); }
    public function show(Request $r): Response { return Response::ok($this->svc->get($r->tenant(), (string) $r->params['id'])); }
    public function create(Request $r): Response { return Response::ok($this->svc->create($r->tenant(), $r->json(), $r), [], 201); }
    public function update(Request $r): Response { return Response::ok($this->svc->update($r->tenant(), (string) $r->params['id'], $r->json(), $r)); }
    public function remove(Request $r): Response { $this->svc->deactivate($r->tenant(), (string) $r->params['id'], $r); return Response::ok(['deactivated' => true]); }
}
