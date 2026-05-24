<?php

declare(strict_types=1);

use AliYavari\IranPayment\Http\Requests\NextpayRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Uri;

// Helpers
$activateFakeRoute = function (): void {
    Route::get('/test', fn (NextpayRequest $request) => response()->json($request->validated(), 200));
};

it('authorizes everyone', function (): void {
    $request = new NextpayRequest();

    expect($request)
        ->authorize()->toBeTrue();
});

it('validates successfully with valid data', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

    $validData = [
        'trans_id' => 'f7c07568-c6d1-4bee-87b1-4a9e5ed2e4c1',
        'order_id' => '123456',
        'amount' => '1000',
    ];

    $response = $this->get(Uri::of('/test')->withQuery($validData));

    $response->assertOk()
        ->assertJson($validData);
});

it('fails to validate if required data are not provided', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

    $response = $this->getJson('/test');

    $response->assertUnprocessable()
        ->assertOnlyInvalid(['trans_id']);
});

it('validates successfully if optional fields are null or empty', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

    $validData = [
        'trans_id' => 'f7c07568-c6d1-4bee-87b1-4a9e5ed2e4c1',
        'order_id' => '',
        'amount' => '',
    ];

    $response = $this->get(Uri::of('/test')->withQuery($validData));

    $response->assertOk()
        ->assertJson($validData);
});
