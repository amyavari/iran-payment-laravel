<?php

declare(strict_types=1);

use AliYavari\IranPayment\Http\Requests\PaypingRequest;
use Illuminate\Support\Facades\Route;

// Helpers
$activateFakeRoute = function (): void {
    Route::post('/test', fn (PaypingRequest $request) => response()->json($request->validated(), 200));
};

it('authorizes everyone', function (): void {
    $request = new PaypingRequest();

    expect($request)
        ->authorize()->toBeTrue();
});

it('validates successfully with valid data', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

    $validData = [
        'status' => 0,
        'errorCode' => 102,
        'data' => [
            'paymentCode' => 'd2e353189823079e1e4181772cff5292',
            'clientRefId' => '',
            'paymentRefId' => 123456,
            'amount' => 1000,
            'gatewayAmount' => 1020,
            'cardNumber' => '123456******4321',
            'cardHashPan' => '13464dasgfasdvad',
        ],
    ];

    $response = $this->post('/test', $validData);

    $response->assertOk()
        ->assertJson($validData);
});

it('fails to validate if required data are not provided', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

    $response = $this->postJson('/test', []);

    $response->assertUnprocessable()
        ->assertOnlyInvalid(['status', 'errorCode', 'data', 'data.paymentCode']);
});

it('validates successfully if optional fields are null or empty', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

    $validData = [
        'status' => 0,
        'errorCode' => null,
        'data' => [
            'paymentCode' => 'd2e353189823079e1e4181772cff5292',
            'clientRefId' => null,
            'paymentRefId' => null,
            'amount' => null,
            'gatewayAmount' => null,
            'cardNumber' => null,
            'cardHashPan' => null,
        ],
    ];

    $response = $this->post('/test', $validData);

    $response->assertOk()
        ->assertJson($validData);
});
