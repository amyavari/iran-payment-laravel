<?php

declare(strict_types=1);

use AliYavari\IranPayment\Http\Requests\BehpardakhtRequest;
use Illuminate\Support\Facades\Route;

// Helpers
$activateFakeRoute = function (): void {
    Route::post('/test', fn (BehpardakhtRequest $request) => response()->json($request->validated(), 200));
};

it('authorizes everyone', function (): void {
    $request = new BehpardakhtRequest();

    expect($request)
        ->authorize()->toBeTrue();
});

it('validates successfully with valid data', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

    $validData = [
        'RefId' => '1234ABab',
        'ResCode' => '1234',
        'SaleOrderId' => '1234',
        'SaleReferenceId' => '1234',
        'CardHolderInfo' => '123-**-123',
        'CardHolderPan' => '1234ABab',
        'FinalAmount' => '1000',
    ];

    $response = $this->post('/test', $validData);

    $response->assertOk()
        ->assertJson($validData);
});

it('fails to validate if required data are not provided', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

    $response = $this->postJson('/test', []);

    $response->assertUnprocessable()
        ->assertOnlyInvalid(['RefId', 'ResCode', 'SaleOrderId']);
});

it('validates successfully if optional fields are null or empty', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

    $validData = [
        'RefId' => '1234ABab',
        'ResCode' => '1234',
        'SaleOrderId' => '1234',
        'SaleReferenceId' => null,
        'CardHolderInfo' => null,
        'CardHolderPan' => null,
        'FinalAmount' => null,
    ];

    $response = $this->post('/test', $validData);

    $response->assertOk()
        ->assertJson($validData);
});
