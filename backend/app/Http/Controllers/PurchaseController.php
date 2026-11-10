<?php
declare(strict_types=1);

namespace LeenTrack\Http\Controllers;

use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Services\PurchaseService;

final class PurchaseController
{
    public function __construct(private readonly PurchaseService $svc)
    {
    }

    private function id(Request $r): string { return (string) $r->params['id']; }

    public function supplierIndex(Request $r): Response { $x = $this->svc->suppliers($r->tenant(), $r->query); return Response::ok($x['rows'], $x['meta']); }
    public function supplierShow(Request $r): Response { return Response::ok($this->svc->supplier($r->tenant(), $this->id($r))); }
    public function supplierCreate(Request $r): Response { return Response::ok($this->svc->createSupplier($r->tenant(), $r->json(), $r), [], 201); }
    public function supplierUpdate(Request $r): Response { return Response::ok($this->svc->updateSupplier($r->tenant(), $this->id($r), $r->json(), $r)); }
    public function supplierArchive(Request $r): Response { $this->svc->archiveSupplier($r->tenant(), $this->id($r), $r); return Response::ok(['archived' => true]); }
    public function supplierRestore(Request $r): Response { return Response::ok($this->svc->restoreSupplier($r->tenant(), $this->id($r), $r)); }

    public function orderIndex(Request $r): Response { $x = $this->svc->orders($r->tenant(), $r->query); return Response::ok($x['rows'], $x['meta']); }
    public function orderShow(Request $r): Response { return Response::ok($this->svc->order($r->tenant(), $this->id($r))); }
    public function orderCreate(Request $r): Response { return Response::ok($this->svc->createOrder($r->tenant(), $r->json(), $r), [], 201); }
    public function orderUpdate(Request $r): Response { return Response::ok($this->svc->updateOrder($r->tenant(), $this->id($r), $r->json(), $r)); }
    public function orderDelete(Request $r): Response { $this->svc->deleteOrder($r->tenant(), $this->id($r), $r); return Response::ok(['deleted' => true]); }
    public function orderPlace(Request $r): Response { return Response::ok($this->svc->place($r->tenant(), $this->id($r), $r)); }
    public function orderCancel(Request $r): Response { return Response::ok($this->svc->cancel($r->tenant(), $this->id($r), $r)); }
    public function orderClose(Request $r): Response { return Response::ok($this->svc->close($r->tenant(), $this->id($r), $r)); }
    public function supplierStatement(Request $r): Response { return Response::ok($this->svc->supplierStatement($r->tenant(), $this->id($r))); }
    public function orderPay(Request $r): Response
    {
        $x = $this->svc->pay($r->tenant(), $this->id($r), $r->json(), $r->header('idempotency-key'), $r);
        return Response::ok($x['result'], ['replayed' => $x['replayed']], $x['replayed'] ? 200 : 201);
    }
    public function orderReceive(Request $r): Response
    {
        $x = $this->svc->receive($r->tenant(), $this->id($r), $r->json(), $r->header('idempotency-key'), $r);
        return Response::ok($x['result'], ['replayed' => $x['replayed']], $x['replayed'] ? 200 : 201);
    }
}
