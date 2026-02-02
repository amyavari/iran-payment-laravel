<?php

declare(strict_types=1);

use AliYavari\IranPayment\Enums\PaymentStatus;
use AliYavari\IranPayment\Models\Payment;
use AliYavari\IranPayment\Tests\Fixtures\TestModel;
use Illuminate\Database\Eloquent\Relations\MorphMany;

it('returns the payment relationship', function (): void {
    // `TestModel` uses the `HasPayment` trait to load payments relationship
    $payable = TestModel::query()->create();

    $payment = new Payment([
        'transaction_id' => fake()->randomNumber(8),
        'amount' => fake()->randomNumber(5),
        'gateway' => fake()->word,
        'gateway_payload' => [],
        'status' => fake()->randomElement(PaymentStatus::class),
        'raw_responses' => [],
    ]);
    $payment->payable()->associate($payable);
    $payment->save();

    expect($payable)
        ->payments()->toBeInstanceOf(MorphMany::class)
        ->payments->first()->is($payment)->toBeTrue();
});
