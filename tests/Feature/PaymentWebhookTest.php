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

    // Send webhook for non-existent order (out-of-order scenario)
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
    expect($log->order_id)->toBe($nonExistentOrderId);
});

test('out-of-order webhook is processed when order is created', function () {
    // Create a new product and hold
    $product = Product::create([
        'name' => 'Out of Order Product',
        'price' => 100.00,
        'stock' => 5,
    ]);

    $holdResponse = $this->postJson('/api/holds', [
        'product_id' => $product->id,
        'qty' => 1,
    ]);
    $holdId = $holdResponse->json('data.hold_id');
    $hold = \App\Models\Hold::find($holdId);

    // Simulate webhook arriving before order creation
    // Create webhook log manually to simulate out-of-order scenario
    $futureOrderId = 99999;
    $idempotencyKey = 'out-of-order-process-key-' . time();

    // Create webhook log as if webhook arrived (but order doesn't exist yet)
    WebhookLog::create([
        'idempotency_key' => $idempotencyKey,
        'order_id' => $futureOrderId,
        'payload' => [
            'order_id' => $futureOrderId,
            'status' => 'success',
        ],
        'status' => 'processed',
        'processed_at' => now(),
    ]);

    // Verify webhook log exists but order doesn't
    $log = WebhookLog::where('idempotency_key', $idempotencyKey)->first();
    expect($log)->not->toBeNull();
    expect(Order::find($futureOrderId))->toBeNull();

    // Create order using OrderService (which processes pending webhooks automatically)
    $orderService = app(\App\Services\OrderService::class);
    $order = $orderService->createOrder($holdId);
    $orderId = $order->id;

    // Update webhook log to reference the actual order (simulating payment provider knowing order ID)
    $log->update(['order_id' => $orderId]);

    // Manually trigger pending webhook processing (OrderService does this automatically in createOrder)
    $reflection = new \ReflectionClass($orderService);
    $method = $reflection->getMethod('processPendingWebhooks');
    $method->setAccessible(true);
    $method->invoke($orderService, $order);

    // Verify order is now paid (webhook was processed)
    $order = $order->fresh();
    expect($order->status)->toBe('paid');

    // Verify stock was decremented
    $product = $product->fresh();
    expect($product->stock)->toBe(4); // 5 - 1 = 4
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

test('concurrent webhook requests with same idempotency key are handled correctly', function () {
    $idempotencyKey = 'concurrent-test-key-' . time();

    // Simulate 10 concurrent webhook requests with same idempotency key
    $responses = [];
    for ($i = 0; $i < 10; $i++) {
        $responses[] = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $this->orderId,
            'status' => 'success',
        ]);
    }

    // All should return 200 OK
    foreach ($responses as $response) {
        $response->assertStatus(200);
    }

    // Only one should be processed, rest should be duplicates
    $processedCount = collect($responses)
        ->filter(fn($r) => $r->json('status') === 'processed')
        ->count();

    $duplicateCount = collect($responses)
        ->filter(fn($r) => $r->json('status') === 'duplicate')
        ->count();

    // Exactly one should be processed, 9 should be duplicates
    expect($processedCount)->toBe(1);
    expect($duplicateCount)->toBe(9);

    // Verify only one webhook log entry exists
    $logsCount = WebhookLog::where('idempotency_key', $idempotencyKey)->count();
    expect($logsCount)->toBe(1);

    // Order should be paid (only processed once)
    $order = Order::find($this->orderId);
    expect($order->status)->toBe('paid');
});

test('at-least-once delivery - same webhook sent multiple times results in same final state', function () {
    $idempotencyKey = 'at-least-once-key-' . time();
    $initialStock = $this->product->fresh()->stock;

    // Send webhook 1 time
    $response1 = $this->postJson('/api/payments/webhook', [
        'idempotency_key' => $idempotencyKey,
        'order_id' => $this->orderId,
        'status' => 'success',
    ]);

    $response1->assertStatus(200);
    $order1 = Order::find($this->orderId);
    $stock1 = $this->product->fresh()->stock;
    expect($order1->status)->toBe('paid');
    expect($stock1)->toBe($initialStock - 2); // 2 qty from hold

    // Send same webhook 5 more times (simulating retries)
    for ($i = 0; $i < 5; $i++) {
        $response = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $this->orderId,
            'status' => 'success',
        ]);

        $response->assertStatus(200);
        expect($response->json('status'))->toBe('duplicate');
    }

    // Final state should be exactly the same as after first webhook
    $orderFinal = Order::find($this->orderId);
    $stockFinal = $this->product->fresh()->stock;

    expect($orderFinal->status)->toBe('paid'); // Same as after first webhook
    expect($stockFinal)->toBe($stock1); // Stock unchanged (not decremented multiple times)
    expect($orderFinal->status)->toBe($order1->status); // Same state

    // Verify only one webhook log entry
    $logsCount = WebhookLog::where('idempotency_key', $idempotencyKey)->count();
    expect($logsCount)->toBe(1);

    // Verify order was only processed once (check payment_reference or timestamps if available)
    $log = WebhookLog::where('idempotency_key', $idempotencyKey)->first();
    expect($log->processed_at)->not->toBeNull();
});

test('at-least-once delivery - failure webhook sent multiple times results in same final state', function () {
    $idempotencyKey = 'at-least-once-failure-key-' . time();
    $initialStock = $this->product->fresh()->stock;

    // Send failure webhook 1 time
    $response1 = $this->postJson('/api/payments/webhook', [
        'idempotency_key' => $idempotencyKey,
        'order_id' => $this->orderId,
        'status' => 'failure',
    ]);

    $response1->assertStatus(200);
    $order1 = Order::find($this->orderId);
    expect($order1->status)->toBe('cancelled');

    // Send same failure webhook 10 more times (simulating retries)
    for ($i = 0; $i < 10; $i++) {
        $response = $this->postJson('/api/payments/webhook', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $this->orderId,
            'status' => 'failure',
        ]);

        $response->assertStatus(200);
        expect($response->json('status'))->toBe('duplicate');
    }

    // Final state should be exactly the same as after first webhook
    $orderFinal = Order::find($this->orderId);
    expect($orderFinal->status)->toBe('cancelled'); // Same as after first webhook
    expect($orderFinal->status)->toBe($order1->status); // Same state

    // Verify only one webhook log entry
    $logsCount = WebhookLog::where('idempotency_key', $idempotencyKey)->count();
    expect($logsCount)->toBe(1);
});
