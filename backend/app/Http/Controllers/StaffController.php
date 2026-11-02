<?php
declare(strict_types=1);

namespace LeenTrack\Http\Controllers;

use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Services\StaffService;

final class StaffController
{
    public function __construct(private readonly StaffService $svc)
    {
    }

    public function index(Request $r): Response { return Response::ok($this->svc->list($r->tenant())); }
    public function invite(Request $r): Response { return Response::ok($this->svc->invite($r->tenant(), $r->json(), $r), [], 201); }
    public function update(Request $r): Response { return Response::ok($this->svc->update($r->tenant(), (string) $r->params['id'], $r->json(), $r)); }
    public function remove(Request $r): Response { $this->svc->remove($r->tenant(), (string) $r->params['id'], $r); return Response::ok(['removed' => true]); }
    public function resend(Request $r): Response { return Response::ok($this->svc->resendInvite($r->tenant(), (string) $r->params['id'], $r)); }
    public function accept(Request $r): Response { return Response::ok($this->svc->accept($r->json(), $r->attributes['auth'] ?? null, $r)); }
}
