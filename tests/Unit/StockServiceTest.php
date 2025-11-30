<?php

use App\Models\Hold;
use App\Models\Product;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('stock service calculates available stock correctly', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 100.00,
        'stock' => 10,
    ]);

    $stockService = new StockService();

    // Initially, all stock is available
    expect($stockService->calculateAvailableStock($product))->toBe(10);

    // Create an active hold
    Hold::create([
        'product_id' => $product->id,
        'qty' => 3,
        'status' => 'active',
        'expires_at' => now()->addMinutes(2),
    ]);

    // Invalidate cache to get fresh calculation
    $stockService->invalidateStockCache($product->id);

    // Available stock should be reduced
    expect($stockService->calculateAvailableStock($product))->toBe(7);

    // Create another hold
    Hold::create([
        'product_id' => $product->id,
        'qty' => 2,
        'status' => 'active',
        'expires_at' => now()->addMinutes(2),
    ]);

    // Invalidate cache to get fresh calculation
    $stockService->invalidateStockCache($product->id);

    // Available stock should be further reduced
    expect($stockService->calculateAvailableStock($product))->toBe(5);
});

test('stock service invalidates cache correctly', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 100.00,
        'stock' => 10,
    ]);

    $stockService = new StockService();

    // Calculate and cache
    $stockService->calculateAvailableStock($product);

    // Invalidate cache
    $stockService->invalidateStockCache($product->id);

    // Cache should be cleared (will recalculate on next call)
    $availableStock = $stockService->calculateAvailableStock($product);
    expect($availableStock)->toBe(10);
});
