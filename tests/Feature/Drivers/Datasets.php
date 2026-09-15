<?php

declare(strict_types=1);

use AliYavari\IranPayment\Enums\ApiMethod;

dataset('gateway_api_methods', [
    'creation' => ['call' => ApiMethod::Create],
    'verification' => ['call' => ApiMethod::Verify],
    'reversal' => ['call' => ApiMethod::Reverse],
]);

dataset('gateway_creation_and_verification_methods', [
    'creation' => ['call' => ApiMethod::Create],
    'verification' => ['call' => ApiMethod::Verify],
]);

dataset('gateway_verification_and_reversal_methods', [
    'verification' => ['call' => ApiMethod::Verify],
    'reversal' => ['call' => ApiMethod::Reverse],
]);

dataset('gateway_phone_number_formats', [
    'With country code' => ['phone' => 989123456789],
    'Without country code, with first zero' => ['phone' => '09123456789'],
    'Without country code, and first zero' => ['phone' => 9123456789],
    'With country code, and first plus' => ['phone' => '+989123456789'],
    'With country code and first zero' => ['phone' => 9809123456789],
    'With country code, first zero and first plus' => ['phone' => '+9809123456789'],
]);

dataset('invalid_string_field_values', [
    'missing value' => ['value' => null, 'given' => 'null'],
    'blank value' => ['value' => '', 'given' => ''],
    'non-castable value' => ['value' => [], 'given' => '[]'],
]);

dataset('invalid_numeric_field_values', [
    'missing value' => ['value' => null, 'given' => 'null'],
    'non-numeric value' => ['value' => 'abc', 'given' => 'abc'],
]);
