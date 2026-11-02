<?php
declare(strict_types=1);

namespace LeenTrack\Http\Controllers;

use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Services\DashboardService;
use LeenTrack\Services\ExpenseService;
use LeenTrack\Services\ProfitService;

final class FinanceController
{
    public function __construct(private readonly ExpenseService $expenses, private readonly ProfitService $profit, private readonly DashboardService $dashboard)
    {
    }

    public function expenseIndex(Request $r): Response { $x = $this->expenses->list($r->tenant(), $r->query); return Response::ok($x['rows'], $x['meta']); }
    public function expenseShow(Request $r): Response { return Response::ok($this->expenses->get($r->tenant(), (string) $r->params['id'])); }
    public function expenseCreate(Request $r): Response { return Response::ok($this->expenses->create($r->tenant(), $r->json(), $r), [], 201); }
    public function expenseUpdate(Request $r): Response { return Response::ok($this->expenses->update($r->tenant(), (string) $r->params['id'], $r->json(), $r)); }
    public function expenseDelete(Request $r): Response { $this->expenses->delete($r->tenant(), (string) $r->params['id'], $r); return Response::ok(['deleted' => true]); }

    public function categoryIndex(Request $r): Response { return Response::ok($this->expenses->categories($r->tenant())); }
    public function categoryCreate(Request $r): Response { return Response::ok($this->expenses->createCategory($r->tenant(), $r->json(), $r), [], 201); }
    public function categoryUpdate(Request $r): Response { return Response::ok($this->expenses->updateCategory($r->tenant(), (string) $r->params['id'], $r->json(), $r)); }
    public function categoryDelete(Request $r): Response { $this->expenses->deleteCategory($r->tenant(), (string) $r->params['id'], $r); return Response::ok(['deleted' => true]); }
    public function categorySeed(Request $r): Response { return Response::ok($this->expenses->seedDefaults($r->tenant(), $r), [], 201); }

    public function profit(Request $r): Response { $x = $this->profit->report($r->tenant(), $r->query); return Response::ok($x['data'], $x['meta']); }
    public function dashboard(Request $r): Response { return Response::ok($this->dashboard->show($r->tenant(), $r->query)); }
}
