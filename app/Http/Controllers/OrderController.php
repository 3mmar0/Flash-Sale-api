<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidHoldException;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private OrderService $orderService
    ) {}

    /**
     * Store a newly created order.
     */
    public function store(CreateOrderRequest $request): JsonResponse
    {
        try {
            $order = $this->orderService->createOrder(
                $request->validated()['hold_id']
            );

            return $this->createdResponse(new OrderResource($order));
        } catch (InvalidHoldException $e) {
            return $this->validationErrorResponse($e->getMessage());
        }
    }
}
