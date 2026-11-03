<?php
declare(strict_types=1);

namespace LeenTrack\Http\Controllers;

use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Services\ReportService;

final class ReportController
{
    public function __construct(private readonly ReportService $svc)
    {
    }

    public function index(Request $r): Response { return Response::ok($this->svc->index($r->tenant())); }
    public function show(Request $r): Response { return $this->svc->run($r->tenant(), (string) $r->params['name'], $r->query); }
}
