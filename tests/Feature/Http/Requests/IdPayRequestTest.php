<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Feature\Http\Requests\IdPayRequestTest; // To avoid helper functions conflict.

use AliYavari\IranPayment\Http\Requests\IdPayRequest;
use Illuminate\Support\Facades\Route;

/**
 * This test file only tests some domain important validation rules.
 */
it('authorizes everyone', function (): void {
    $request = new IdPayRequest();

    expect($request)
        ->authorize()->toBeTrue();
});

it('validates successfully with valid data', function (): void {
    activateFakeRoute();

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

it('fails to validate if required data are not provided', function (): void {
    activateFakeRoute();

    $response = $this->postJson('/test', []);

    $response->assertUnprocessable()
        ->assertOnlyInvalid(['status', 'track_id', 'id', 'order_id']);
});

// ------------
// Helpers
// ------------

function activateFakeRoute(): void
{
    Route::post('/test', fn (IdPayRequest $request) => response()->json($request->validated(), 200));
}
