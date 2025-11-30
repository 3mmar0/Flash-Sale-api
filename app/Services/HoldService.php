<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidHoldException;
use App\Models\Hold;
use App\Models\Product;
use App\Traits\RetriesOnDeadlock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HoldService
{
    use RetriesOnDeadlock;

    public function __construct(
        private StockService $stockService
    ) {}

    /**
     * Create a hold with row-level locking and transaction.
     *
     * @throws InsufficientStockException
     */
    public function createHold(int $productId, int $qty): Hold
    {
        return $this->retryOnDeadlock(function () use ($productId, $qty) {
            return DB::transaction(function () use ($productId, $qty) {
                // Lock the product row for update
                $product = Product::lockForUpdate()->findOrFail($productId);

                // Calculate available stock
                $availableStock = $this->stockService->calculateAvailableStock($product);

                if ($qty > $availableStock) {
                    Log::warning('Insufficient stock for hold creation', [
                        'product_id' => $productId,
                        'requested_qty' => $qty,
                        'available_stock' => $availableStock,
                    ]);

                    throw new InsufficientStockException(
                        "Insufficient stock. Available: {$availableStock}, Requested: {$qty}"
                    );
                }

                // Create the hold
                $hold = Hold::create([
                    'product_id' => $productId,
                    'qty' => $qty,
                    'status' => 'active',
                    'expires_at' => now()->addMinutes(2),
                ]);

                // Invalidate cache
                $this->stockService->invalidateStockCache($productId);

                Log::info('Hold created successfully', [
                    'hold_id' => $hold->id,
                    'product_id' => $productId,
                    'qty' => $qty,
                ]);

                return $hold;
            });
        });
    }

    /**
     * Expire a hold and restore stock availability.
     */
    public function expireHold(Hold $hold): bool
    {
        return DB::transaction(function () use ($hold) {
            // Lock the hold to prevent double-processing
            $hold = Hold::lockForUpdate()->findOrFail($hold->id);

            // Only expire if still active
            if ($hold->status !== 'active') {
                return false;
            }

            $hold->markAsExpired();

            // Invalidate cache
            $this->stockService->invalidateStockCache($hold->product_id);

            Log::info('Hold expired', [
                'hold_id' => $hold->id,
                'product_id' => $hold->product_id,
                'qty' => $hold->qty,
            ]);

            return true;
        });
    }

    /**
     * Restore stock from a used hold (when payment fails).
     */
    public function restoreStockFromUsedHold(Hold $hold): bool
    {
        return DB::transaction(function () use ($hold) {
            // Lock the hold to prevent double-processing
            $hold = Hold::lockForUpdate()->findOrFail($hold->id);

            // Only restore if hold is used (not already expired)
            if ($hold->status === 'expired') {
                return false;
            }

            // Mark as expired to restore stock
            $hold->markAsExpired();

            // Invalidate cache
            $this->stockService->invalidateStockCache($hold->product_id);

            Log::info('Stock restored from used hold', [
                'hold_id' => $hold->id,
                'product_id' => $hold->product_id,
                'qty' => $hold->qty,
            ]);

            return true;
        });
    }

    /**
     * Validate if a hold is valid for use.
     *
     * @throws InvalidHoldException
     */
    public function validateHold(Hold $hold): void
    {
        if ($hold->status !== 'active') {
            throw new InvalidHoldException('Hold is not active');
        }

        if ($hold->isExpired()) {
            throw new InvalidHoldException('Hold has expired');
        }
    }
}
