<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Feature\Http\Requests\ZibalRequestTest; // To avoid helper functions conflict.

use AliYavari\IranPayment\Http\Requests\ZibalRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Uri;

/**
 * This test file only tests some domain important validation rules.
 */
it('authorizes everyone', function (): void {
    $request = new ZibalRequest();

    expect($request)
        ->authorize()->toBeTrue();
});

it('validates successfully with valid data', function (): void {
    activateFakeRoute();

    $validData = [
        'status' => '2',
        'success' => '1',
        'trackId' => '9900',
    ];

    $response = $this->get(Uri::of('/test')->withQuery($validData));

    $response->assertOk()
        ->assertJson($validData);
});

it('fails to validate if required data are not provided', function (): void {
    activateFakeRoute();

    $response = $this->getJson('/test');

    $response->assertUnprocessable()
        ->assertOnlyInvalid(['status', 'success', 'trackId']);
});

// ------------
// Helpers
// ------------

function activateFakeRoute(): void
{
    Route::get('/test', fn (ZibalRequest $request) => response()->json($request->validated(), 200));
}
