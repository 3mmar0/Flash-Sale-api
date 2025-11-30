<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * Calculate available stock for a product.
     * This considers active holds that reduce available stock.
     */
    public function calculateAvailableStock(Product $product): int
    {
        $cacheKey = "product_available_stock:{$product->id}";

        return Cache::remember($cacheKey, 60, function () use ($product) {
            $activeHoldsQty = DB::table('holds')
                ->where('product_id', $product->id)
                ->where('status', 'active')
                ->sum('qty');

            return max(0, $product->stock - (int) $activeHoldsQty);
        });
    }

    /**
     * Invalidate stock cache for a product.
     */
    public function invalidateStockCache(int $productId): void
    {
        Cache::forget("product:{$productId}");
        Cache::forget("product_available_stock:{$productId}");
    }

    /**
     * Get product with cache.
     */
    public function getCachedProduct(int $productId): ?Product
    {
        $cacheKey = "product:{$productId}";

        return Cache::remember($cacheKey, 300, function () use ($productId) {
            return Product::find($productId);
        });
    }
}

