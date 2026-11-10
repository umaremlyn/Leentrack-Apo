<?php
declare(strict_types=1);

namespace LeenTrack\Http\Controllers;

use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Sync\SyncPullService;
use LeenTrack\Sync\SyncService;

final class SyncController
{
    public function __construct(private readonly SyncService $sync, private readonly SyncPullService $pull)
    {
    }

    public function info(Request $r): Response { return Response::ok($this->sync->info()); }
    public function push(Request $r): Response { return Response::ok($this->sync->push($r->tenant(), $r->json(), $r)); }
    public function pull(Request $r): Response { return Response::ok($this->pull->pull($r->tenant(), $r->json())); }
    public function operations(Request $r): Response { $x = $this->sync->operations($r->tenant(), $r->query); return Response::ok($x['rows'], $x['meta']); }
    public function retry(Request $r): Response { return Response::ok($this->sync->retry($r->tenant(), (string) $r->params['id'], $r)); }
    public function acceptPrices(Request $r): Response { return Response::ok($this->sync->retry($r->tenant(), (string) $r->params['id'], $r, true)); }
    public function discard(Request $r): Response { return Response::ok($this->sync->discard($r->tenant(), (string) $r->params['id'], $r->json(), $r)); }
}
