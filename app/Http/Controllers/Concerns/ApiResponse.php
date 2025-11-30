<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

trait ApiResponse
{
    /**
     * Return a successful JSON response.
     */
    protected function successResponse(
        JsonResource|array $data,
        int $statusCode = 200,
        string $message = null
    ): JsonResponse {
        $response = [];

        if ($message) {
            $response['message'] = $message;
        }

        if ($data instanceof JsonResource) {
            return $data->response()->setStatusCode($statusCode);
        }

        $response['data'] = $data;

        return response()->json($response, $statusCode);
    }

    /**
     * Return an error JSON response.
     */
    protected function errorResponse(
        string $message,
        int $statusCode = 400,
        array $errors = null
    ): JsonResponse {
        $response = [
            'message' => $message,
        ];

        if ($errors) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Return a 404 Not Found response.
     */
    protected function notFoundResponse(string $message = 'Resource not found'): JsonResponse
    {
        return $this->errorResponse($message, 404);
    }

    /**
     * Return a 422 Unprocessable Entity response.
     */
    protected function validationErrorResponse(string $message, array $errors = null): JsonResponse
    {
        return $this->errorResponse($message, 422, $errors);
    }

    /**
     * Return a 201 Created response with resource.
     */
    protected function createdResponse(JsonResource $resource): JsonResponse
    {
        return $resource->response()->setStatusCode(201);
    }

    /**
     * Return a 200 OK response with data.
     * For webhooks and direct data responses (not wrapped in 'data' key).
     */
    protected function okResponse(array $data, string $message = null): JsonResponse
    {
        if ($message) {
            $data['message'] = $message;
        }
        return response()->json($data, 200);
    }
}
