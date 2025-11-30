<?php

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use App\Services\HoldService;
use App\Services\PaymentWebhookService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('payment webhook service processes success correctly', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 100.00,
        'stock' => 10,
    ]);

    $hold = Hold::create([
        'product_id' => $product->id,
        'qty' => 2,
        'status' => 'active',
        'expires_at' => now()->addMinutes(2),
    ]);

    $hold->markAsUsed();

    $order = Order::create([
        'hold_id' => $hold->id,
        'status' => 'pending_payment',
    ]);

    $webhookService = new PaymentWebhookService(
        new HoldService(new StockService()),
        new StockService()
    );

    $result = $webhookService->processWebhook('test-key-1', $order->id, 'success');

    expect($result['status'])->toBe('processed');
    expect($order->fresh()->status)->toBe('paid');
});

test('payment webhook service processes failure correctly', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 100.00,
        'stock' => 10,
    ]);

    $hold = Hold::create([
        'product_id' => $product->id,
        'qty' => 2,
        'status' => 'active',
        'expires_at' => now()->addMinutes(2),
    ]);

    $hold->markAsUsed();

    $order = Order::create([
        'hold_id' => $hold->id,
        'status' => 'pending_payment',
    ]);

    $webhookService = new PaymentWebhookService(
        new HoldService(new StockService()),
        new StockService()
    );

    $result = $webhookService->processWebhook('test-key-2', $order->id, 'failure');

    expect($result['status'])->toBe('processed');
    expect($order->fresh()->status)->toBe('cancelled');
});

test('payment webhook service handles idempotency correctly', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 100.00,
        'stock' => 10,
    ]);

    $hold = Hold::create([
        'product_id' => $product->id,
        'qty' => 2,
        'status' => 'active',
        'expires_at' => now()->addMinutes(2),
    ]);

    $hold->markAsUsed();

    $order = Order::create([
        'hold_id' => $hold->id,
        'status' => 'pending_payment',
    ]);

    $webhookService = new PaymentWebhookService(
        new HoldService(new StockService()),
        new StockService()
    );

    $idempotencyKey = 'duplicate-key';

    // First call
    $result1 = $webhookService->processWebhook($idempotencyKey, $order->id, 'success');
    expect($result1['status'])->toBe('processed');

    // Second call with same key
    $result2 = $webhookService->processWebhook($idempotencyKey, $order->id, 'success');
    expect($result2['status'])->toBe('duplicate');
});
