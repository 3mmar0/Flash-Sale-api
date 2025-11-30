<?php

namespace App\Services;

use App\Exceptions\InvalidHoldException;
use App\Models\Hold;
use App\Models\Order;
use App\Models\WebhookLog;
use App\Services\HoldService;
use App\Services\PaymentWebhookService;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderService
{
    public function __construct(
        private HoldService $holdService,
        private StockService $stockService,
        private PaymentWebhookService $webhookService
    ) {}

    /**
     * Create an order from a valid hold.
     *
     * @throws InvalidHoldException
     */
    public function createOrder(int $holdId): Order
    {
        return DB::transaction(function () use ($holdId) {
            // Lock the hold to prevent concurrent order creation
            $hold = Hold::lockForUpdate()->findOrFail($holdId);

            // Validate the hold
            $this->holdService->validateHold($hold);

            // Check if hold is already used
            if ($hold->order()->exists()) {
                throw new InvalidHoldException('Hold has already been used');
            }

            // Mark hold as used
            $hold->markAsUsed();

            // Create the order
            $order = Order::create([
                'hold_id' => $hold->id,
                'status' => 'pending_payment',
            ]);

            // Check for pending webhooks (out-of-order webhook handling)
            $this->processPendingWebhooks($order);

            // Invalidate cache
            $this->stockService->invalidateStockCache($hold->product_id);

            Log::info('Order created successfully', [
                'order_id' => $order->id,
                'hold_id' => $holdId,
            ]);

            return $order;
        });
    }

    /**
     * Validate if a hold can be used for order creation.
     *
     * @throws InvalidHoldException
     */
    public function validateHoldForOrder(Hold $hold): void
    {
        $this->holdService->validateHold($hold);

        if ($hold->order()->exists()) {
            throw new InvalidHoldException('Hold has already been used');
        }
    }

    /**
     * Process any pending webhooks for the newly created order.
     * Handles out-of-order webhook delivery.
     */
    protected function processPendingWebhooks(Order $order): void
    {
        // Find webhooks that arrived before order creation
        $pendingWebhooks = WebhookLog::where('order_id', $order->id)
            ->where('status', 'processed')
            ->get();

        foreach ($pendingWebhooks as $webhookLog) {
            $payload = $webhookLog->payload;
            $status = $payload['status'] ?? null;
            $idempotencyKey = $webhookLog->idempotency_key;

            if ($status) {
                try {
                    // Process the webhook now that order exists
                    $this->webhookService->processWebhook(
                        $idempotencyKey,
                        $order->id,
                        $status
                    );

                    Log::info('Processed pending webhook after order creation', [
                        'order_id' => $order->id,
                        'idempotency_key' => $idempotencyKey,
                        'status' => $status,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to process pending webhook', [
                        'order_id' => $order->id,
                        'idempotency_key' => $idempotencyKey,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
