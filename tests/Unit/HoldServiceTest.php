<?php

use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidHoldException;
use App\Models\Hold;
use App\Models\Product;
use App\Services\HoldService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('hold service creates hold successfully', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 100.00,
        'stock' => 10,
    ]);

    $holdService = new HoldService(new StockService());

    $hold = $holdService->createHold($product->id, 3);

    expect($hold)->toBeInstanceOf(Hold::class);
    expect($hold->qty)->toBe(3);
    expect($hold->status)->toBe('active');
    expect($hold->product_id)->toBe($product->id);
});

test('hold service throws exception for insufficient stock', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 100.00,
        'stock' => 5,
    ]);

    $holdService = new HoldService(new StockService());

    expect(fn() => $holdService->createHold($product->id, 10))
        ->toThrow(InsufficientStockException::class);
});

test('hold service expires hold correctly', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 100.00,
        'stock' => 10,
    ]);

    $hold = Hold::create([
        'product_id' => $product->id,
        'qty' => 3,
        'status' => 'active',
        'expires_at' => now()->addMinutes(2),
    ]);

    $holdService = new HoldService(new StockService());
    $result = $holdService->expireHold($hold);

    expect($result)->toBeTrue();
    expect($hold->fresh()->status)->toBe('expired');
});

test('hold service validates hold correctly', function () {
    $product = Product::create([
        'name' => 'Test Product',
        'price' => 100.00,
        'stock' => 10,
    ]);

    $hold = Hold::create([
        'product_id' => $product->id,
        'qty' => 3,
        'status' => 'active',
        'expires_at' => now()->addMinutes(2),
    ]);

    $holdService = new HoldService(new StockService());

    // Valid hold should not throw
    $holdService->validateHold($hold);
    expect(true)->toBeTrue();

    // Expired hold should throw
    $hold->update(['status' => 'expired']);
    expect(fn() => $holdService->validateHold($hold->fresh()))
        ->toThrow(InvalidHoldException::class);
});
