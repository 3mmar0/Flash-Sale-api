<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidHoldException;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function __construct(
        private OrderService $orderService
    ) {}

    /**
     * Store a newly created order.
     */
    public function store(CreateOrderRequest $request): JsonResponse|OrderResource
    {
        try {
            $order = $this->orderService->createOrder(
                $request->validated()['hold_id']
            );

            return (new OrderResource($order))->response()->setStatusCode(201);
        } catch (InvalidHoldException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
