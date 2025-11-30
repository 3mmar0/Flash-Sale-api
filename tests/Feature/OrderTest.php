<?php

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->product = Product::create([
        'name' => 'Flash Sale Product',
        'price' => 150.00,
        'stock' => 10,
    ]);
});

test('order creation from valid hold succeeds', function () {
    // Create a hold
    $holdResponse = $this->postJson('/api/holds', [
        'product_id' => $this->product->id,
        'qty' => 2,
    ]);
    $holdId = $holdResponse->json('data.hold_id');

    // Create order from hold
    $orderResponse = $this->postJson('/api/orders', [
        'hold_id' => $holdId,
    ]);

    $orderResponse->assertStatus(201)
        ->assertJsonStructure([
            'data' => [
                'order_id',
                'status',
            ],
        ])
        ->assertJson([
            'data' => [
                'status' => 'pending_payment',
            ],
        ]);

    // Verify hold is marked as used
    $hold = Hold::find($holdId);
    expect($hold->status)->toBe('used');
});

test('order creation from expired hold fails', function () {
    // Create a hold
    $holdResponse = $this->postJson('/api/holds', [
        'product_id' => $this->product->id,
        'qty' => 2,
    ]);
    $holdId = $holdResponse->json('data.hold_id');

    // Expire the hold
    $hold = Hold::find($holdId);
    $hold->update([
        'expires_at' => now()->subMinute(),
        'status' => 'expired',
    ]);

    // Try to create order
    $orderResponse = $this->postJson('/api/orders', [
        'hold_id' => $holdId,
    ]);

    $orderResponse->assertStatus(422)
        ->assertJsonStructure(['message']);
});

test('order creation from used hold fails', function () {
    // Create a hold
    $holdResponse = $this->postJson('/api/holds', [
        'product_id' => $this->product->id,
        'qty' => 2,
    ]);
    $holdId = $holdResponse->json('data.hold_id');

    // Create first order
    $this->postJson('/api/orders', ['hold_id' => $holdId])->assertStatus(201);

    // Try to create second order from same hold
    $orderResponse = $this->postJson('/api/orders', [
        'hold_id' => $holdId,
    ]);

    $orderResponse->assertStatus(422)
        ->assertJsonStructure(['message']);
});
