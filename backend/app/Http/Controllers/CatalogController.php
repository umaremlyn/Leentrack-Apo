<?php
declare(strict_types=1);

namespace LeenTrack\Http\Controllers;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Services\CategoryService;
use LeenTrack\Services\ProductImportService;
use LeenTrack\Services\ProductService;
use LeenTrack\Services\StockService;

final class CatalogController
{
    public function __construct(
        private readonly CategoryService $categories,
        private readonly ProductService $products,
        private readonly ProductImportService $import,
        private readonly StockService $stock,
    ) {
    }

    // categories
    public function categoryIndex(Request $r): Response { return Response::ok($this->categories->list($r->tenant())); }
    public function categoryCreate(Request $r): Response { return Response::ok($this->categories->create($r->tenant(), $r->json(), $r), [], 201); }
    public function categoryUpdate(Request $r): Response { return Response::ok($this->categories->update($r->tenant(), (string) $r->params['id'], $r->json(), $r)); }
    public function categoryDelete(Request $r): Response { $this->categories->delete($r->tenant(), (string) $r->params['id'], $r); return Response::ok(['deleted' => true]); }

    // products
    public function productIndex(Request $r): Response { $x = $this->products->list($r->tenant(), $r->query); return Response::ok($x['rows'], $x['meta']); }
    public function productShow(Request $r): Response { return Response::ok($this->products->get($r->tenant(), (string) $r->params['id'])); }
    public function productCreate(Request $r): Response { return Response::ok($this->products->create($r->tenant(), $r->json(), $r), [], 201); }
    public function productUpdate(Request $r): Response { return Response::ok($this->products->update($r->tenant(), (string) $r->params['id'], $r->json(), $r)); }
    public function productArchive(Request $r): Response { $this->products->archive($r->tenant(), (string) $r->params['id'], $r); return Response::ok(['archived' => true]); }
    public function productRestore(Request $r): Response { return Response::ok($this->products->restore($r->tenant(), (string) $r->params['id'], $r)); }
    public function productExport(Request $r): Response { return $this->products->export($r->tenant()); }

    public function productImport(Request $r): Response
    {
        $mode = $r->query['mode'] ?? 'validate';
        if (!in_array($mode, ['validate', 'commit'], true)) { throw ApiException::validation(['mode' => ['Must be validate or commit.']]); }
        $loc = $r->query['location_id'] ?? null;
        if ($loc !== null && (!is_string($loc) || !\LeenTrack\Core\Uuid::isValid($loc))) { throw ApiException::validation(['location_id' => ['Must be a valid id.']]); }
        return Response::ok($this->import->run($r->tenant(), $r->csvText(), $mode, $loc, $r));
    }

    // stock
    public function stockAdjust(Request $r): Response { return Response::ok($this->stock->adjust($r->tenant(), $r->json(), $r), [], 201); }
    public function stockLevels(Request $r): Response { $x = $this->stock->levels($r->tenant(), $r->query); return Response::ok($x['rows'], $x['meta']); }
    public function stockMovements(Request $r): Response { $x = $this->stock->movements($r->tenant(), $r->query); return Response::ok($x['rows'], $x['meta']); }
    public function stockValuation(Request $r): Response { return Response::ok($this->stock->valuation($r->tenant(), $r->query)); }
}
