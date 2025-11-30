<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Requests\CreateHoldRequest;
use App\Http\Resources\HoldResource;
use App\Services\HoldService;
use Illuminate\Http\JsonResponse;

class HoldController extends Controller
{
    use ApiResponse;

    public function __construct(
        private HoldService $holdService
    ) {}

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
