<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Resources\ProductResource;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    use ApiResponse;

    public function __construct(
        private StockService $stockService
    ) {}

    /**
     * Display the specified product.
     */
    public function show(int $id): JsonResponse|ProductResource
    {
        $product = $this->stockService->getCachedProduct($id);

        if (!$product) {
            return $this->notFoundResponse('Product not found');
        }

        // Ensure available_stock is calculated (this will use cache)
        $product->available_stock = $this->stockService->calculateAvailableStock($product);

        return new ProductResource($product);
    }
}
