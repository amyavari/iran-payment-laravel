<?php

declare(strict_types=1);

use AliYavari\IranPayment\Enums\PaymentStatus;
use AliYavari\IranPayment\Models\Payment;
use AliYavari\IranPayment\Tests\Fixtures\TestModel;

it('adds a the first raw response when raw_responses is initially null or empty', function (): void {
    setTestNow('2025-12-10 18:30:10');

    $payment = new Payment();

    $payment->addRawResponse('method', ['key' => 'value']);

    expect($payment)
        ->raw_responses->toBe([
            'method_20251210183010' => ['key' => 'value'],
        ]);

});

it('adds a new raw response without overwriting existing entries', function (): void {
    setTestNow('2025-12-10 18:30:10');

    $payment = new Payment([
        'raw_responses' => [
            'old_key' => 'old_value',
        ],
    ]);

    $payment->addRawResponse('method', ['key' => 'value']);

    expect($payment)
        ->raw_responses->toBe([
            'old_key' => 'old_value',
            'method_20251210183010' => ['key' => 'value'],
        ]);
});

it('returns only successful payment relationship', function (): void {
    $successfulPayment = createPayment(PaymentStatus::Successful);
    $failedPayment = createPayment(PaymentStatus::Failed);
    $pendingPayment = createPayment(PaymentStatus::Pending);

    expect(Payment::query()->successful()->get())
        ->toHaveCount(1)
        ->first()->is($successfulPayment)->toBeTrue();
});

it('returns only failed payment relationship', function (): void {
    $successfulPayment = createPayment(PaymentStatus::Successful);
    $failedPayment = createPayment(PaymentStatus::Failed);
    $pendingPayment = createPayment(PaymentStatus::Pending);

    expect(Payment::query()->failed()->get())
        ->toHaveCount(1)
        ->first()->is($failedPayment)->toBeTrue();
});

it('returns only pending payment relationship', function (): void {
    $successfulPayment = createPayment(PaymentStatus::Successful);
    $failedPayment = createPayment(PaymentStatus::Failed);
    $pendingPayment = createPayment(PaymentStatus::Pending);

    expect(Payment::query()->pending()->get())
        ->toHaveCount(1)
        ->first()->is($pendingPayment)->toBeTrue();
});

// ------------
// Helpers
// ------------

function createPayment(PaymentStatus $status): Payment
{
    $data = [
        'transaction_id' => fake()->randomNumber(8),
        'amount' => fake()->randomNumber(5),
        'gateway' => fake()->word,
        'gateway_payload' => [],
        'status' => $status,
        'raw_responses' => [],
    ];

    $payment = new Payment($data);
    $payment->payable()->associate(TestModel::query()->create());
    $payment->save();

    return $payment;
}
