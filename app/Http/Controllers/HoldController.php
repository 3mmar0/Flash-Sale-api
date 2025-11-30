<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStockException;
use App\Http\Requests\CreateHoldRequest;
use App\Http\Resources\HoldResource;
use App\Services\HoldService;
use Illuminate\Http\JsonResponse;

class HoldController extends Controller
{
    public function __construct(
        private HoldService $holdService
    ) {}

    /**
     * Store a newly created hold.
     */
    public function store(CreateHoldRequest $request): JsonResponse|HoldResource
    {
        try {
            $hold = $this->holdService->createHold(
                $request->validated()['product_id'],
                $request->validated()['qty']
            );

            return (new HoldResource($hold))->response()->setStatusCode(201);
        } catch (InsufficientStockException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
