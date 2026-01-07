<?php

declare(strict_types=1);

use AliYavari\IranPayment\Models\Payment;

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
