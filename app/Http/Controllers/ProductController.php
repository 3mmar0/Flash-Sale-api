<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function __construct(
        private StockService $stockService
    ) {
    }

    /**
     * Display the specified product.
     */
    public function show(int $id): JsonResponse|ProductResource
    {
        $product = $this->stockService->getCachedProduct($id);

        if (!$product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        // Ensure available_stock is calculated (this will use cache)
        $product->available_stock = $this->stockService->calculateAvailableStock($product);

        return new ProductResource($product);
    }
}

