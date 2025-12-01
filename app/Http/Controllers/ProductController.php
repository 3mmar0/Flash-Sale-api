<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ProductResourceCollection;
use App\Models\Product;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    use ApiResponse;

    public function __construct(
        private StockService $stockService
    ) {}

    /**
     * Display a listing of products.
     */
    public function index(): ProductResourceCollection
    {
        $products = Product::paginate(15);

        // Calculate available_stock for each product using cached method
        // This ensures consistency with the show() method and uses cache for performance
        $products->getCollection()->transform(function ($product) {
            $product->available_stock = $this->stockService->calculateAvailableStock($product);
            return $product;
        });

        return ProductResource::collection($products);
    }

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
