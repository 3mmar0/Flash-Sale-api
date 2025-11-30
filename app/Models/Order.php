<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'hold_id',
        'status',
        'payment_reference',
    ];

    /**
     * Get the hold that owns the order.
     */
    public function hold(): BelongsTo
    {
        return $this->belongsTo(Hold::class);
    }

    /**
     * Scope to get pending payment orders.
     */
    public function scopePendingPayment($query)
    {
        return $query->where('status', 'pending_payment');
    }

    /**
     * Scope to get paid orders.
     */
    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    /**
     * Scope to get cancelled orders.
     */
    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    /**
     * Mark the order as paid.
     */
    public function markAsPaid(?string $paymentReference = null): bool
    {
        return $this->update([
            'status' => 'paid',
            'payment_reference' => $paymentReference,
        ]);
    }

    /**
     * Mark the order as cancelled.
     */
    public function markAsCancelled(): bool
    {
        return $this->update(['status' => 'cancelled']);
    }
}

