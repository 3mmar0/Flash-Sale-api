<?php

namespace App\Services;

use App\Models\Order;
use App\Models\WebhookLog;
use App\Services\HoldService;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentWebhookService
{
    public function __construct(
        private HoldService $holdService,
        private StockService $stockService
    ) {}

    /**
     * Process webhook idempotently.
     * Handles duplicates and out-of-order webhooks.
     * Thread-safe for concurrent requests with same idempotency key.
     */
    public function processWebhook(string $idempotencyKey, int $orderId, string $status): array
    {
        return DB::transaction(function () use ($idempotencyKey, $orderId, $status) {
            // Check for duplicate webhook WITHIN transaction to prevent race conditions
            // Use lockForUpdate to prevent concurrent processing of same idempotency key
            $existingLog = WebhookLog::where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingLog) {
                Log::info('Duplicate webhook detected', [
                    'idempotency_key' => $idempotencyKey,
                    'order_id' => $orderId,
                ]);

                return $this->handleWebhookDuplicate($existingLog);
            }

            // Try to create webhook log - handle unique constraint violation gracefully
            try {
                $webhookLog = WebhookLog::create([
                    'idempotency_key' => $idempotencyKey,
                    'order_id' => $orderId,
                    'payload' => [
                        'order_id' => $orderId,
                        'status' => $status,
                    ],
                    'status' => 'processed',
                    'processed_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Handle race condition: if another request created the log between check and create
                if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry')) {
                    // Unique constraint violation - another request processed this webhook
                    $existingLog = WebhookLog::where('idempotency_key', $idempotencyKey)->first();
                    if ($existingLog) {
                        Log::info('Duplicate webhook detected (race condition handled)', [
                            'idempotency_key' => $idempotencyKey,
                            'order_id' => $orderId,
                        ]);
                        return $this->handleWebhookDuplicate($existingLog);
                    }
                }
                throw $e; // Re-throw if it's a different error
            }

            // Try to find the order
            $order = Order::find($orderId);

            // Handle out-of-order webhook (order doesn't exist yet)
            if (!$order) {
                Log::warning('Webhook received before order creation', [
                    'idempotency_key' => $idempotencyKey,
                    'order_id' => $orderId,
                ]);

                // Store for later processing (could be handled by a job that retries)
                // For now, we'll mark it as processed but log the issue
                return [
                    'status' => 'processed',
                    'message' => 'Order not found, will be processed when order is created',
                ];
            }

            // Process the webhook based on status
            if ($status === 'success') {
                $this->handleSuccess($order);
            } else {
                $this->handleFailure($order);
            }

            $webhookLog->markAsProcessed();

            return [
                'status' => 'processed',
                'order_id' => $order->id,
                'order_status' => $order->status,
            ];
        });
    }

    /**
     * Handle duplicate webhook.
     */
    protected function handleWebhookDuplicate(WebhookLog $webhookLog): array
    {
        $order = $webhookLog->order_id ? Order::find($webhookLog->order_id) : null;

        return [
            'status' => 'duplicate',
            'order_id' => $webhookLog->order_id,
            'order_status' => $order?->status,
            'processed_at' => $webhookLog->processed_at,
        ];
    }

    /**
     * Process webhook payload directly (for pending webhooks).
     * Skips idempotency check since webhook was already stored.
     */
    public function processWebhookPayload(Order $order, string $status): void
    {
        DB::transaction(function () use ($order, $status) {
            // Process the webhook based on status
            if ($status === 'success') {
                $this->handleSuccess($order);
            } else {
                $this->handleFailure($order);
            }
        });
    }

    /**
     * Handle successful payment.
     */
    protected function handleSuccess(Order $order): void
    {
        if ($order->status === 'paid') {
            Log::info('Order already paid', ['order_id' => $order->id]);
            return;
        }

        $order->markAsPaid();

        // Decrement product stock permanently
        $hold = $order->hold;
        if ($hold && $hold->product) {
            $productId = $hold->product_id;
            $qty = $hold->qty;

            // Lock product row and decrement stock
            DB::transaction(function () use ($productId, $qty) {
                $product = \App\Models\Product::lockForUpdate()->find($productId);
                if ($product) {
                    $product->decrement('stock', $qty);

                    // Invalidate cache
                    $this->stockService->invalidateStockCache($productId);
                }
            });

            $product = \App\Models\Product::find($productId);
            Log::info('Order marked as paid and stock decremented', [
                'order_id' => $order->id,
                'hold_id' => $order->hold_id,
                'product_id' => $productId,
                'qty_sold' => $qty,
                'remaining_stock' => $product->stock,
            ]);
        } else {
            Log::info('Order marked as paid', [
                'order_id' => $order->id,
                'hold_id' => $order->hold_id,
            ]);
        }
    }

    /**
     * Handle failed payment - cancel order and restore stock.
     */
    protected function handleFailure(Order $order): void
    {
        // Cannot cancel an already paid order
        if ($order->status === 'paid') {
            Log::warning('Cannot cancel paid order', [
                'order_id' => $order->id,
                'current_status' => $order->status,
            ]);
            return;
        }

        if ($order->status === 'cancelled') {
            Log::info('Order already cancelled', ['order_id' => $order->id]);
            return;
        }

        $order->markAsCancelled();

        // Restore stock from the hold
        $hold = $order->hold;
        if ($hold) {
            if ($hold->status === 'active') {
                // If still active, use expireHold
                $this->holdService->expireHold($hold);
            } else if ($hold->status === 'used') {
                // If used, restore stock by expiring it
                $this->holdService->restoreStockFromUsedHold($hold);
            }
        }

        Log::info('Order cancelled and stock restored', [
            'order_id' => $order->id,
            'hold_id' => $hold->id,
            'product_id' => $hold->product_id,
        ]);
    }

    /**
     * Handle out-of-order webhook (webhook arrives before order creation).
     */
    public function handleOutOfOrderWebhook(string $idempotencyKey, int $orderId, string $status): void
    {
        // This could be implemented as a job that retries
        // For now, we store it and process when order is created
        WebhookLog::create([
            'idempotency_key' => $idempotencyKey,
            'order_id' => $orderId,
            'payload' => [
                'order_id' => $orderId,
                'status' => $status,
            ],
            'status' => 'processed',
        ]);

        Log::info('Out-of-order webhook stored', [
            'idempotency_key' => $idempotencyKey,
            'order_id' => $orderId,
        ]);
    }
}
