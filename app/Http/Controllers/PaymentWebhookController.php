<?php

namespace App\Http\Controllers;

use App\Http\Requests\WebhookRequest;
use App\Services\PaymentWebhookService;
use Illuminate\Http\JsonResponse;

class PaymentWebhookController extends Controller
{
    public function __construct(
        private PaymentWebhookService $webhookService
    ) {
    }

    /**
     * Handle payment webhook.
     * Always returns 200 OK for idempotency.
     */
    public function webhook(WebhookRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->webhookService->processWebhook(
            $validated['idempotency_key'],
            $validated['order_id'],
            $validated['status']
        );

        // Always return 200 OK as per requirements
        return response()->json($result, 200);
    }
}

