<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'price' => (float) $this->price,
            'available_stock' => $this->available_stock,
        ];
    }

    /**
     * Create a new resource collection instance.
     *
     * @param  mixed  $resource
     * @return \App\Http\Resources\ProductResourceCollection
     */
    public static function collection($resource)
    {
        return new ProductResourceCollection($resource);
    }
}

