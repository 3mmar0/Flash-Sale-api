<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class WebhookRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // In production, validate webhook signature here
        // For now, we'll allow all requests
        return $this->validateSignature();
    }

    /**
     * Validate webhook signature.
     */
    protected function validateSignature(): bool
    {
        $secret = config('webhook.secret');

        if (!$secret) {
            // If no secret configured, allow (for development)
            return true;
        }

        // Simple signature validation (adjust based on your payment provider)
        $signature = $this->header('X-Webhook-Signature');
        $payload = $this->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('Invalid webhook signature', [
                'received' => $signature,
                'expected' => $expectedSignature,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string'],
            'order_id' => ['required', 'integer'],
            'status' => ['required', 'string', 'in:success,failure'],
        ];
    }
}

