<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    use ApiResponse;

    public function __construct(
        private StockService $stockService
    ) {}

    /**
     * Display a listing of products.
     */
    public function index(): AnonymousResourceCollection
    {
        $products = Product::paginate(15);

        // Get all product IDs from the current page
        $productIds = $products->pluck('id')->toArray();

        // Calculate active holds quantity for all products in one query (avoid N+1)
        $activeHoldsByProduct = DB::table('holds')
            ->whereIn('product_id', $productIds)
            ->where('status', 'active')
            ->groupBy('product_id')
            ->select('product_id', DB::raw('SUM(qty) as total_qty'))
            ->pluck('total_qty', 'product_id')
            ->toArray();

        // Calculate available_stock for each product without N+1 queries
        $products->getCollection()->transform(function ($product) use ($activeHoldsByProduct) {
            $activeHoldsQty = (int) ($activeHoldsByProduct[$product->id] ?? 0);
            $product->available_stock = max(0, $product->stock - $activeHoldsQty);
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
