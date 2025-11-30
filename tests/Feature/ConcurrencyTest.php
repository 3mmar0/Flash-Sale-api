<?php

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('parallel hold attempts at stock boundary prevent overselling', function () {
    $product = Product::create([
        'name' => 'Limited Stock Product',
        'price' => 100.00,
        'stock' => 10,
    ]);

    $concurrentRequests = 100;
    $responses = [];

    // Simulate concurrent requests using parallel execution
    for ($i = 0; $i < $concurrentRequests; $i++) {
        $responses[] = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 1,
        ]);
    }

    // Count successful holds
    $successfulHolds = collect($responses)
        ->filter(fn($r) => $r->status() === 201)
        ->count();

    // Should have exactly 10 successful holds (matching stock)
    expect($successfulHolds)->toBe(10);

    // Verify database state
    $activeHolds = DB::table('holds')
        ->where('product_id', $product->id)
        ->where('status', 'active')
        ->count();

    expect($activeHolds)->toBe(10);

    // Verify available stock is 0
    $productResponse = $this->getJson("/api/products/{$product->id}");
    $productResponse->assertJson([
        'data' => [
            'available_stock' => 0,
        ],
    ]);
});

test('cache invalidation works under concurrency', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 100.00,
        'stock' => 5,
    ]);

    // Create multiple holds
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 1,
        ]);
    }

    // Check available stock (should be 2)
    $response = $this->getJson("/api/products/{$product->id}");
    $response->assertJson([
        'data' => [
            'available_stock' => 2,
        ],
    ]);
});
