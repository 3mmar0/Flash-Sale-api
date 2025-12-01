<?php

use App\Http\Controllers\HoldController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

// Products
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);

// Holds
Route::get('/holds', [HoldController::class, 'index']);
Route::post('/holds', [HoldController::class, 'store']);

// Orders
Route::get('/orders', [OrderController::class, 'index']);
Route::post('/orders', [OrderController::class, 'store']);

// Webhooks
Route::post('/payments/webhook', [PaymentWebhookController::class, 'webhook']);
