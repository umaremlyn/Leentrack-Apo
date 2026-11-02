<?php
declare(strict_types=1);

namespace LeenTrack\Http\Controllers;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Services\BrandingService;
use LeenTrack\Services\SaleService;

final class SaleController
{
    public function __construct(private readonly SaleService $sales, private readonly BrandingService $branding)
    {
    }

    public function checkout(Request $r): Response
    {
        $res = $this->sales->checkout($r->tenant(), $r->json(), $r->header('idempotency-key'), $r);
        return Response::ok($res['sale'], ['replayed' => $res['replayed']], $res['replayed'] ? 200 : 201);
    }

    public function index(Request $r): Response { $x = $this->sales->list($r->tenant(), $r->query); return Response::ok($x['rows'], $x['meta']); }
    public function show(Request $r): Response { return Response::ok($this->sales->get($r->tenant(), (string) $r->params['id'])); }
    public function edit(Request $r): Response { return Response::ok($this->sales->edit($r->tenant(), (string) $r->params['id'], $r->json(), $r)); }
    public function void(Request $r): Response { return Response::ok($this->sales->void($r->tenant(), (string) $r->params['id'], $r->json(), $r)); }

    public function document(Request $r): Response
    {
        $format = $r->query['format'] ?? 'json';
        if (!is_string($format) || !in_array($format, ['json', 'pdf'], true)) { throw ApiException::validation(['format' => ['Must be json or pdf.']]); }
        return Response::ok($this->sales->document($r->tenant(), (string) $r->params['id'], $format));
    }

    public function brandingShow(Request $r): Response { return Response::ok($this->branding->show($r->tenant())); }
    public function brandingSave(Request $r): Response { return Response::ok($this->branding->save($r->tenant(), $r->json(), $r)); }
}
