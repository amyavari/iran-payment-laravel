<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Feature\Http\Requests\PepRequestTest; // To avoid helper functions conflict.

use AliYavari\IranPayment\Http\Requests\PepRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Uri;

/**
 * This test file only tests some domain important validation rules.
 */
it('authorizes everyone', function (): void {
    $request = new PepRequest();

    expect($request)
        ->authorize()->toBeTrue();
});

it('validates successfully with valid data', function (): void {
    activateFakeRoute();

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

it('fails to validate if required data are not provided', function (): void {
    activateFakeRoute();

    $response = $this->getJson('/test');

    $response->assertUnprocessable()
        ->assertOnlyInvalid(['status', 'invoiceId']);
});

// ------------
// Helpers
// ------------

function activateFakeRoute(): void
{
    Route::get('/test', fn (PepRequest $request) => response()->json($request->validated(), 200));
}
