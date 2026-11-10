<?php
declare(strict_types=1);

namespace LeenTrack\Http\Controllers;

use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Services\TransferService;

final class TransferController
{
    public function __construct(private readonly TransferService $svc)
    {
    }

    private function id(Request $r): string { return (string) $r->params['id']; }

    public function index(Request $r): Response { $x = $this->svc->list($r->tenant(), $r->query); return Response::ok($x['rows'], $x['meta']); }
    public function show(Request $r): Response { return Response::ok($this->svc->get($r->tenant(), $this->id($r))); }
    public function create(Request $r): Response { return Response::ok($this->svc->create($r->tenant(), $r->json(), $r), [], 201); }
    public function update(Request $r): Response { return Response::ok($this->svc->update($r->tenant(), $this->id($r), $r->json(), $r)); }
    public function cancel(Request $r): Response { return Response::ok($this->svc->cancel($r->tenant(), $this->id($r), $r)); }
    public function dispatch(Request $r): Response { return $this->moved($this->svc->dispatch($r->tenant(), $this->id($r), $r->json(), $r->header('idempotency-key'), $r)); }
    public function receive(Request $r): Response { return $this->moved($this->svc->receive($r->tenant(), $this->id($r), $r->json(), $r->header('idempotency-key'), $r)); }

    /** @param array{result:array<string,mixed>,replayed:bool} $x */
    private function moved(array $x): Response { return Response::ok($x['result'], ['replayed' => $x['replayed']], $x['replayed'] ? 200 : 201); }
}
