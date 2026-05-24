<?php

declare(strict_types=1);

use AliYavari\IranPayment\Http\Requests\SepRequest;
use Illuminate\Support\Facades\Route;

// Helpers
$activateFakeRoute = function (): void {
    Route::post('/test', fn (SepRequest $request) => response()->json($request->validated(), 200));
};

it('authorizes everyone', function (): void {
    $request = new SepRequest();

    expect($request)
        ->authorize()->toBeTrue();
});

it('validates successfully with valid data', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

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

it('fails to validate if required data are not provided', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

    $response = $this->postJson('/test', []);

    $response->assertUnprocessable()
        ->assertOnlyInvalid(['State', 'Status', 'ResNum']);
});

it('validates successfully if optional fields are null or empty', function () use ($activateFakeRoute): void {
    $activateFakeRoute();

    $validData = [
        'MID' => null,
        'State' => 'OK',
        'Status' => '2',
        'RRN' => null,
        'RefNum' => null,
        'ResNum' => '123456789012345',
        'TerminalId' => null,
        'TraceNo' => null,
        'Amount' => null,
        'SecurePan' => null,
        'HashedCardNumber' => null,
    ];

    $response = $this->post('/test', $validData);

    $response->assertOk()
        ->assertJson($validData);
});
