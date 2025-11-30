<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'idempotency_key',
        'order_id',
        'payload',
        'status',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    /**
     * Scope to get processed webhooks.
     */
    public function scopeProcessed($query)
    {
        return $query->where('status', 'processed');
    }

    /**
     * Scope to get duplicate webhooks.
     */
    public function scopeDuplicate($query)
    {
        return $query->where('status', 'duplicate');
    }

    /**
     * Mark the webhook as processed.
     */
    public function markAsProcessed(): bool
    {
        return $this->update([
            'status' => 'processed',
            'processed_at' => now(),
        ]);
    }
}

