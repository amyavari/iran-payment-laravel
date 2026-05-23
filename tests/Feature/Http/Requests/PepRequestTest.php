<?php

declare(strict_types=1);

use AliYavari\IranPayment\Http\Requests\PepRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Uri;

// Helpers
$activateFakeRoute = function (): void {
    Route::get('/test', fn (PepRequest $request) => response()->json($request->validated(), 200));
};

it('authorizes everyone', function (): void {
    $request = new PepRequest();

    expect($request)
        ->authorize()->toBeTrue();
});

it('validates successfully with valid data', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

    $validData = [
        'invoiceId' => '123456789012345',
        'status' => 'success',
        'referenceNumber' => '1224563',
        'trackId' => '1234',
    ];

    $response = $this->get(Uri::of('/test')->withQuery($validData));

    $response->assertOk()
        ->assertJson($validData);
});

it('fails to validate if required data are not provided', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

    $response = $this->getJson('/test');

    $response->assertUnprocessable()
        ->assertOnlyInvalid(['status', 'invoiceId']);
});

it('validates successfully if optional fields are null or empty', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

    $validData = [
        'invoiceId' => '123456789012345',
        'status' => 'success',
        'referenceNumber' => '',
        'trackId' => '',
    ];

    $response = $this->get(Uri::of('/test')->withQuery($validData));

    $response->assertOk()
        ->assertJson($validData);
});
