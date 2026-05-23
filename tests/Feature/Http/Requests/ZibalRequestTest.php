<?php

declare(strict_types=1);

use AliYavari\IranPayment\Http\Requests\ZibalRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Uri;

// Helpers
$activateFakeRoute = function (): void {
    Route::get('/test', fn (ZibalRequest $request) => response()->json($request->validated(), 200));
};

it('authorizes everyone', function (): void {
    $request = new ZibalRequest();

    expect($request)
        ->authorize()->toBeTrue();
});

it('validates successfully with valid data', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

    $validData = [
        'status' => '2',
        'success' => '1',
        'trackId' => '9900',
    ];

    $response = $this->get(Uri::of('/test')->withQuery($validData));

    $response->assertOk()
        ->assertJson($validData);
});

it('fails to validate if required data are not provided', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

    $response = $this->getJson('/test');

    $response->assertUnprocessable()
        ->assertOnlyInvalid(['status', 'success', 'trackId']);
});
