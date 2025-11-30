<?php

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('product endpoint returns correct available stock', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 100.00,
        'stock' => 50,
    ]);

    $response = $this->getJson("/api/products/{$product->id}");

    $response->assertStatus(200)
        ->assertJson([
            'data' => [
                'id' => $product->id,
                'name' => 'Test Product',
                'price' => 100.0,
                'available_stock' => 50,
            ],
        ]);
});

test('product endpoint returns 404 for non-existent product', function () {
    $response = $this->getJson('/api/products/999');

    $response->assertStatus(404);
});

test('product caching works correctly', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 100.00,
        'stock' => 50,
    ]);

    // First request
    $response1 = $this->getJson("/api/products/{$product->id}");
    $response1->assertStatus(200);

    // Second request should use cache
    $response2 = $this->getJson("/api/products/{$product->id}");
    $response2->assertStatus(200)
        ->assertJson([
            'data' => [
                'available_stock' => 50,
            ],
        ]);
});
