<?php

declare(strict_types=1);

use AliYavari\IranPayment\Http\Requests\ZarinpalRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Uri;

$activateFakeRoute = function (): void {
    Route::get('/test', fn (ZarinpalRequest $request) => response()->json($request->validated(), 200));
};

it('authorizes everyone', function (): void {
    $request = new ZarinpalRequest();

    expect($request)
        ->authorize()->toBeTrue();
});

it('validates successfully with valid data', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

    $validData = [
        'Authority' => '1234ABab',
        'Status' => 'OK',
    ];

    $response = $this->get(Uri::of('/test')->withQuery($validData));

    $response->assertOk()
        ->assertJson($validData);
});

it('fails to validate if required data are not provided', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

    $response = $this->getJson('/test');

    $response->assertUnprocessable()
        ->assertOnlyInvalid(['Authority', 'Status']);
});
