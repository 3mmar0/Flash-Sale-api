<?php

use App\Models\Hold;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->product = Product::create([
        'name' => 'Flash Sale Product',
        'price' => 150.00,
        'stock' => 10,
    ]);
});

test('hold creation reduces available stock', function () {
    $response = $this->postJson('/api/holds', [
        'product_id' => $this->product->id,
        'qty' => 3,
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'data' => [
                'hold_id',
                'expires_at',
            ],
        ]);

    // Check available stock is reduced
    $productResponse = $this->getJson("/api/products/{$this->product->id}");
    $productResponse->assertJson([
        'data' => [
            'available_stock' => 7, // 10 - 3
        ],
    ]);
});

test('hold creation with insufficient stock fails', function () {
    $response = $this->postJson('/api/holds', [
        'product_id' => $this->product->id,
        'qty' => 15, // More than available
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure([
            'message',
        ]);
});

test('hold expiration restores stock', function () {
    // Create a hold
    $holdResponse = $this->postJson('/api/holds', [
        'product_id' => $this->product->id,
        'qty' => 5,
    ]);
    $holdResponse->assertStatus(201);

    // Verify stock is reduced
    $productResponse = $this->getJson("/api/products/{$this->product->id}");
    $productResponse->assertJson([
        'data' => [
            'available_stock' => 5,
        ],
    ]);

    // Manually expire the hold (simulating job)
    $hold = Hold::find($holdResponse->json('data.hold_id'));
    $hold->update(['expires_at' => now()->subMinute()]);

    // Run the expiration job
    $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

    // Or manually expire
    $hold->markAsExpired();
    Cache::forget("product_available_stock:{$this->product->id}");

    // Verify stock is restored
    $productResponse = $this->getJson("/api/products/{$this->product->id}");
    $productResponse->assertJson([
        'data' => [
            'available_stock' => 10,
        ],
    ]);
});

test('parallel hold creation at stock boundary prevents overselling', function () {
    $product = Product::create([
        'name' => 'Limited Product',
        'price' => 100.00,
        'stock' => 10,
    ]);

    $responses = [];
    $concurrentRequests = 20; // More than stock

    // Simulate concurrent requests
    for ($i = 0; $i < $concurrentRequests; $i++) {
        $responses[] = $this->postJson('/api/holds', [
            'product_id' => $product->id,
            'qty' => 1,
        ]);
    }

    // Count successful holds
    $successfulHolds = collect($responses)->filter(fn($r) => $r->status() === 201)->count();

    // Should have exactly 10 successful holds (matching stock)
    expect($successfulHolds)->toBe(10);

    // Verify total holds created
    $holdsCount = Hold::where('product_id', $product->id)
        ->where('status', 'active')
        ->count();

    expect($holdsCount)->toBe(10);

    // Verify available stock is 0
    $productResponse = $this->getJson("/api/products/{$product->id}");
    $productResponse->assertJson([
        'data' => [
            'available_stock' => 0,
        ],
    ]);
});
