<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Feature\Http\Requests\SadadRequestTest; // To avoid helper functions conflict.

use AliYavari\IranPayment\Http\Requests\SadadRequest;
use Illuminate\Support\Facades\Route;

/**
 * This test file only tests some domain important validation rules.
 */
it('authorizes everyone', function (): void {
    $request = new SadadRequest();

    expect($request)
        ->authorize()->toBeTrue();
});

it('validates successfully with valid data', function (): void {
    activateFakeRoute();

    $validData = [
        'ResCode' => '0',
        'OrderId' => '123456789012345',
        'SwitchResCod' => '1',
        'Token' => 'kjslflnvda13464sdv13a',
        'HashedCardNo' => 'ashdlf46463',
        'PrimaryAccNo' => '123456******1234',
        'CardHolderFullName' => 'نام',
    ];

    $response = $this->post('/test', $validData);

    $response->assertOk()
        ->assertJson($validData);
});

it('fails to validate if required data are not provided', function (): void {
    activateFakeRoute();

    $response = $this->postJson('/test');

    $response->assertUnprocessable()
        ->assertOnlyInvalid(['ResCode', 'OrderId']);
});

it('validates successfully if optional fields are null or empty', function (): void {
    activateFakeRoute();

    $validData = [
        'ResCode' => '0',
        'OrderId' => '123456789012345',
        'SwitchResCod' => null,
        'Token' => null,
        'HashedCardNo' => null,
        'PrimaryAccNo' => null,
        'CardHolderFullName' => null,
    ];

    $response = $this->post('/test', $validData);

    $response->assertOk()
        ->assertJson($validData);
});

// ------------
// Helpers
// ------------

function activateFakeRoute(): void
{
    Route::post('/test', fn (SadadRequest $request) => response()->json($request->validated(), 200));
}
