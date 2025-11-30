<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'price',
        'stock',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
    ];

    /**
     * Get all holds for this product.
     */
    public function holds(): HasMany
    {
        return $this->hasMany(Hold::class);
    }

    /**
     * Calculate available stock considering active holds.
     * Active holds reduce available stock immediately.
     */
    public function getAvailableStockAttribute(): int
    {
        $activeHoldsQty = $this->holds()
            ->where('status', 'active')
            ->sum('qty');

        return max(0, $this->stock - $activeHoldsQty);
    }

    /**
     * Scope to get available products.
     */
    public function scopeAvailable($query)
    {
        return $query->where('stock', '>', 0);
    }
}

