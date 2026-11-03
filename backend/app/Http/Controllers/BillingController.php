<?php
declare(strict_types=1);

namespace LeenTrack\Http\Controllers;

use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Services\BillingService;

final class BillingController
{
    public function __construct(private readonly BillingService $svc)
    {
    }

    public function plans(Request $r): Response { return Response::ok($this->svc->plans($r->tenant(), $r->query)); }
    public function summary(Request $r): Response { return Response::ok($this->svc->summary($r->tenant())); }
    public function history(Request $r): Response { $x = $this->svc->history($r->tenant(), $r->query); return Response::ok($x['rows'], $x['meta']); }
    public function checkout(Request $r): Response { return Response::ok($this->svc->checkout($r->tenant(), $r->json(), $r), [], 201); }
    public function verify(Request $r): Response { return Response::ok($this->svc->verify($r->tenant(), $r->json(), $r)); }

    /** Public endpoint, authenticated only by the provider's signature over the raw body. Always answers quickly with a small body. */
    public function paystackWebhook(Request $r): Response
    {
        $x = $this->svc->webhook($r->rawBody(), $r->header('x-paystack-signature'));
        return Response::json($x['status'], $x['body']);
    }
}
