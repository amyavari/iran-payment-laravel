<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Feature\Http\Requests\ZarinpalRequestTest; // To avoid helper functions conflict.

use AliYavari\IranPayment\Http\Requests\ZarinpalRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Uri;

/**
 * This test file only tests some domain important validation rules.
 */
it('authorizes everyone', function (): void {
    $request = new ZarinpalRequest();

    expect($request)
        ->authorize()->toBeTrue();
});

it('validates successfully with valid data', function (): void {
    activateFakeRoute();

    $validData = [
        'Authority' => '1234ABab',
        'Status' => 'OK',
    ];

    $response = $this->get(Uri::of('/test')->withQuery($validData));

    $response->assertOk()
        ->assertJson($validData);
});

it('fails to validate if required data are not provided', function (): void {
    activateFakeRoute();

    $response = $this->getJson('/test');

    $response->assertUnprocessable()
        ->assertOnlyInvalid(['Authority', 'Status']);
});

// ------------
// Helpers
// ------------

function activateFakeRoute(): void
{
    Route::get('/test', fn (ZarinpalRequest $request) => response()->json($request->validated(), 200));
}
