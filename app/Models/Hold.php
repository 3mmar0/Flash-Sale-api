<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Hold extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'qty',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'qty' => 'integer',
    ];

    /**
     * Get the product that owns the hold.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the order associated with the hold.
     */
    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    /**
     * Scope to get active holds.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to get expired holds.
     */
    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    /**
     * Scope to get unused holds.
     */
    public function scopeUnused($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Check if the hold is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast() || $this->status === 'expired';
    }

    /**
     * Mark the hold as expired.
     */
    public function markAsExpired(): bool
    {
        return $this->update(['status' => 'expired']);
    }

    /**
     * Mark the hold as used.
     */
    public function markAsUsed(): bool
    {
        return $this->update(['status' => 'used']);
    }
}

