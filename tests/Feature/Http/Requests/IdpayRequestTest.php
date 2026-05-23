<?php

declare(strict_types=1);

use AliYavari\IranPayment\Http\Requests\IdpayRequest;
use Illuminate\Support\Facades\Route;

// Helpers
$activateFakeRoute = function (): void {
    Route::post('/test', fn (IdpayRequest $request) => response()->json($request->validated(), 200));
};

it('authorizes everyone', function (): void {
    $request = new IdpayRequest();

    expect($request)
        ->authorize()->toBeTrue();
});

it('validates successfully with valid data', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

    $validData = [
        'status' => '100',
        'track_id' => '888001',
        'id' => 'd2e353189823079e1e4181772cff5292',
        'order_id' => 'abc123',
    ];

    $response = $this->post('/test', $validData);

    $response->assertOk()
        ->assertJson($validData);
});

it('fails to validate if required data are not provided', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

    $response = $this->postJson('/test', []);

    $response->assertUnprocessable()
        ->assertOnlyInvalid(['status', 'track_id', 'id', 'order_id']);
});
