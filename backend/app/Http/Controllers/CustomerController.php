<?php
declare(strict_types=1);

namespace LeenTrack\Http\Controllers;

use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Services\CustomerGroupService;
use LeenTrack\Services\CustomerService;
use LeenTrack\Services\LoyaltyService;

final class CustomerController
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly CustomerGroupService $groups,
        private readonly LoyaltyService $loyalty,
    ) {
    }

    public function index(Request $r): Response { $x = $this->customers->list($r->tenant(), $r->query); return Response::ok($x['rows'], $x['meta']); }
    public function show(Request $r): Response { return Response::ok($this->customers->get($r->tenant(), (string) $r->params['id'])); }
    public function create(Request $r): Response { return Response::ok($this->customers->create($r->tenant(), $r->json(), $r), [], 201); }
    public function update(Request $r): Response { return Response::ok($this->customers->update($r->tenant(), (string) $r->params['id'], $r->json(), $r)); }
    public function archive(Request $r): Response { $this->customers->archive($r->tenant(), (string) $r->params['id'], $r); return Response::ok(['archived' => true]); }
    public function restore(Request $r): Response { return Response::ok($this->customers->restore($r->tenant(), (string) $r->params['id'], $r)); }
    public function ledger(Request $r): Response { $x = $this->customers->ledger($r->tenant(), (string) $r->params['id'], $r->query); return Response::ok($x['rows'], $x['meta']); }

    public function groupIndex(Request $r): Response { return Response::ok($this->groups->list($r->tenant())); }
    public function groupCreate(Request $r): Response { return Response::ok($this->groups->create($r->tenant(), $r->json(), $r), [], 201); }
    public function groupUpdate(Request $r): Response { return Response::ok($this->groups->update($r->tenant(), (string) $r->params['id'], $r->json(), $r)); }
    public function groupDelete(Request $r): Response { $this->groups->delete($r->tenant(), (string) $r->params['id'], $r); return Response::ok(['deleted' => true]); }

    public function loyaltyShow(Request $r): Response { return Response::ok($this->loyalty->showSettings($r->tenant())); }
    public function loyaltySave(Request $r): Response { return Response::ok($this->loyalty->saveSettings($r->tenant(), $r->json(), $r)); }
}
