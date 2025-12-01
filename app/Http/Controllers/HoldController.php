<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Requests\CreateHoldRequest;
use App\Http\Resources\HoldResource;
use App\Models\Hold;
use App\Services\HoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HoldController extends Controller
{
    use ApiResponse;

    public function __construct(
        private HoldService $holdService
    ) {}

    /**
     * Display a listing of holds.
     */
    public function index(): AnonymousResourceCollection
    {
        // Eager load product and order to avoid N+1 queries
        $holds = Hold::with(['product', 'order'])->latest()->paginate(15);

        return HoldResource::collection($holds);
    }

    /**
     * Store a newly created hold.
     */
    public function store(CreateHoldRequest $request): JsonResponse
    {
        try {
            $hold = $this->holdService->createHold(
                $request->validated()['product_id'],
                $request->validated()['qty']
            );

            return $this->createdResponse(new HoldResource($hold));
        } catch (InsufficientStockException $e) {
            return $this->validationErrorResponse($e->getMessage());
        }
    }
}
