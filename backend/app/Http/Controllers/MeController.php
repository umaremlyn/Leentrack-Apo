<?php
declare(strict_types=1);

namespace LeenTrack\Http\Controllers;

use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Services\MeService;

final class MeController
{
    public function __construct(private readonly MeService $me)
    {
    }

    public function show(Request $r): Response { return Response::ok($this->me->show($r->auth())); }
    public function update(Request $r): Response { return Response::ok($this->me->update($r->auth(), $r->json(), $r)); }
    public function sessions(Request $r): Response { return Response::ok($this->me->sessions($r->auth())); }
    public function revokeSession(Request $r): Response { $this->me->revokeSession($r->auth(), (string) $r->params['id'], $r); return Response::ok(['revoked' => true]); }
}
