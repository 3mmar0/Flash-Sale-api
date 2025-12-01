<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidHoldException;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    use ApiResponse;

    public function __construct(
        private OrderService $orderService
    ) {}

    /**
     * Display a listing of orders.
     */
    public function index(): AnonymousResourceCollection
    {
        // Eager load hold and hold.product to avoid N+1 queries
        $orders = Order::with(['hold.product'])->latest()->paginate(15);

        return OrderResource::collection($orders);
    }

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
