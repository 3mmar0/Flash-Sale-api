<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'order_id' => $this->id,
            'status' => $this->status,
            'payment_reference' => $this->payment_reference,
            'hold_id' => $this->hold_id,
            'product' => $this->whenLoaded('hold.product', function () {
                return [
                    'id' => $this->hold->product->id,
                    'name' => $this->hold->product->name,
                    'price' => (float) $this->hold->product->price,
                ];
            }),
            'qty' => $this->whenLoaded('hold', function () {
                return $this->hold->qty;
            }),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }

    /**
     * Create a new resource collection instance.
     *
     * @param  mixed  $resource
     * @return \App\Http\Resources\OrderResourceCollection
     */
    public static function collection($resource)
    {
        return new OrderResourceCollection($resource);
    }
}
