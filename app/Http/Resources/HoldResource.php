<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HoldResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'hold_id' => $this->id,
            'product_id' => $this->product_id,
            'qty' => $this->qty,
            'status' => $this->status,
            'expires_at' => $this->expires_at->toIso8601String(),
            'product' => $this->whenLoaded('product', function () {
                return [
                    'id' => $this->product->id,
                    'name' => $this->product->name,
                    'price' => (float) $this->product->price,
                ];
            }),
            'order' => $this->whenLoaded('order', function () {
                return [
                    'order_id' => $this->order->id,
                    'status' => $this->order->status,
                ];
            }),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
