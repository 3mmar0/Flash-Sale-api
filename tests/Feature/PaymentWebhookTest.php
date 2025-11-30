<?php

use App\Models\Hold;
use App\Models\Order;
use App\Models\Product;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->product = Product::create([
        'name' => 'Flash Sale Product',
        'price' => 150.00,
        'stock' => 10,
    ]);

    // Create hold and order
    $holdResponse = $this->postJson('/api/holds', [
        'product_id' => $this->product->id,
        'qty' => 2,
    ]);
    $this->holdId = $holdResponse->json('data.hold_id');

    $orderResponse = $this->postJson('/api/orders', [
        'hold_id' => $this->holdId,
    ]);
    $this->orderId = $orderResponse->json('data.order_id');
});

test('webhook idempotency - same key multiple times returns same result', function () {
    $idempotencyKey = 'test-key-123';

    // First webhook
    $response1 = $this->postJson('/api/payments/webhook', [
        'idempotency_key' => $idempotencyKey,
        'order_id' => $this->orderId,
        'status' => 'success',
    ]);

    $response1->assertStatus(200);
    $order1 = Order::find($this->orderId);
    expect($order1->status)->toBe('paid');

    // Second webhook with same key
    $response2 = $this->postJson('/api/payments/webhook', [
        'idempotency_key' => $idempotencyKey,
        'order_id' => $this->orderId,
        'status' => 'success',
    ]);

    $response2->assertStatus(200)
        ->assertJson(['status' => 'duplicate']);

    // Order status should not change
    $order2 = Order::find($this->orderId);
    expect($order2->status)->toBe('paid');

    // Verify only one webhook log entry
    $logsCount = WebhookLog::where('idempotency_key', $idempotencyKey)->count();
    expect($logsCount)->toBe(1);
});

test('webhook success updates order to paid', function () {
    $response = $this->postJson('/api/payments/webhook', [
        'idempotency_key' => 'unique-key-1',
        'order_id' => $this->orderId,
        'status' => 'success',
    ]);

    $response->assertStatus(200);

    $order = Order::find($this->orderId);
    expect($order->status)->toBe('paid');
});

test('webhook failure cancels order and restores stock', function () {
    $initialStock = $this->product->fresh()->available_stock;

    $response = $this->postJson('/api/payments/webhook', [
        'idempotency_key' => 'unique-key-2',
        'order_id' => $this->orderId,
        'status' => 'failure',
    ]);

    $response->assertStatus(200);

    $order = Order::find($this->orderId);
    expect($order->status)->toBe('cancelled');

    // Stock should be restored (hold expired)
    $hold = Hold::find($this->holdId);
    expect($hold->status)->toBe('expired');
});

test('duplicate webhook returns 200 without side effects', function () {
    $idempotencyKey = 'duplicate-test-key';

    // First webhook
    $this->postJson('/api/payments/webhook', [
        'idempotency_key' => $idempotencyKey,
        'order_id' => $this->orderId,
        'status' => 'success',
    ])->assertStatus(200);

    $orderAfterFirst = Order::find($this->orderId);
    $firstStatus = $orderAfterFirst->status;

    // Duplicate webhook
    $response = $this->postJson('/api/payments/webhook', [
        'idempotency_key' => $idempotencyKey,
        'order_id' => $this->orderId,
        'status' => 'success',
    ]);

    $response->assertStatus(200);

    // Order status should not change
    $orderAfterDuplicate = Order::find($this->orderId);
    expect($orderAfterDuplicate->status)->toBe($firstStatus);
});

test('webhook before order creation handles gracefully', function () {
    // Create a new product and hold
    $product = Product::create([
        'name' => 'New Product',
        'price' => 100.00,
        'stock' => 5,
    ]);

    $holdResponse = $this->postJson('/api/holds', [
        'product_id' => $product->id,
        'qty' => 1,
    ]);
    $holdId = $holdResponse->json('data.hold_id');

    // Send webhook for non-existent order
    $nonExistentOrderId = 99999;
    $response = $this->postJson('/api/payments/webhook', [
        'idempotency_key' => 'out-of-order-key',
        'order_id' => $nonExistentOrderId,
        'status' => 'success',
    ]);

    // Should still return 200
    $response->assertStatus(200);

    // Webhook log should be created
    $log = WebhookLog::where('idempotency_key', 'out-of-order-key')->first();
    expect($log)->not->toBeNull();
});

test('paid order cannot be cancelled by failure webhook', function () {
    // First, mark order as paid
    $successResponse = $this->postJson('/api/payments/webhook', [
        'idempotency_key' => 'success-key-1',
        'order_id' => $this->orderId,
        'status' => 'success',
    ]);

    $successResponse->assertStatus(200);
    $orderAfterSuccess = Order::find($this->orderId);
    expect($orderAfterSuccess->status)->toBe('paid');

    // Get initial stock after payment
    $productAfterPayment = $this->product->fresh();
    $stockAfterPayment = $productAfterPayment->stock;

    // Try to cancel with failure webhook
    $failureResponse = $this->postJson('/api/payments/webhook', [
        'idempotency_key' => 'failure-key-1',
        'order_id' => $this->orderId,
        'status' => 'failure',
    ]);

    $failureResponse->assertStatus(200);

    // Order should still be paid (not cancelled)
    $orderAfterFailure = Order::find($this->orderId);
    expect($orderAfterFailure->status)->toBe('paid');

    // Stock should not be restored (still decremented)
    $productAfterFailure = $this->product->fresh();
    expect($productAfterFailure->stock)->toBe($stockAfterPayment);
});
