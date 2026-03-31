<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Feature\Http\Requests\SepRequestTest; // To avoid helper functions conflict.

use AliYavari\IranPayment\Http\Requests\SepRequest;
use Illuminate\Support\Facades\Route;

/**
 * This test file only tests some domain important validation rules.
 */
it('authorizes everyone', function (): void {
    $request = new SepRequest();

    expect($request)
        ->authorize()->toBeTrue();
});

it('validates successfully with valid data', function (): void {
    activateFakeRoute();

    $validData = [
        'MID' => '1234',
        'State' => 'OK',
        'Status' => '2',
        'RRN' => '227926981246',
        'RefNum' => 'Aht+dgVAEUDZ++54+qyrGzncrgA1kySE+NbxBUcNfbJafVj3f5',
        'ResNum' => '123456789012345',
        'TerminalId' => '1234',
        'TraceNo' => '123456',
        'Amount' => '1000',
        'SecurePan' => '123456******1234',
        'HashedCardNumber' => '1234ABsab',
    ];

    $response = $this->post('/test', $validData);

    $response->assertOk()
        ->assertJson($validData);
});

it('fails to validate if required data are not provided', function (): void {
    activateFakeRoute();

    $response = $this->postJson('/test', []);

    $response->assertUnprocessable()
        ->assertOnlyInvalid(['State', 'Status', 'ResNum']);
});

// ------------
// Helpers
// ------------

function activateFakeRoute(): void
{
    Route::post('/test', fn (SepRequest $request) => response()->json($request->validated(), 200));
}
