<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Helpers;

use AliYavari\IranPayment\Drivers\PaypingDriver;
use AliYavari\IranPayment\Facades\Payment;
use Illuminate\Support\Facades\Config;

final class PaypingHelper extends AbstractHelper
{
    /**
     * {@inheritdoc}
     */
    public static function setDriverConfigs(): void
    {
        Config::set('iran-payment.gateways.payping.callback_url', 'http://callback.test');
        Config::set('iran-payment.gateways.payping.token', 'token');
    }

    /**
     * {@inheritdoc}
     */
    public static function driver(): PaypingDriver
    {
        return Payment::gateway('payping');
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulCreationResponse(): array
    {
        return [
            'paymentCode' => 'd2e353189823079e1e4181772cff5292',
            'url' => 'https://api.payping.ir/v3/pay/start/d2e3531898',
            'amount' => 1000,
            'payerWage' => 10,
            'businessWage' => 10,
            'gatewayAmount' => 1020,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulVerificationResponse(): array
    {
        return [
            'amount' => 1_000,
            'cardNumber' => '123456******1234',
            'cardHashPan' => 'E59FA6241C94B8836E3D03120DF33E80FD988888BBA0A122240C2E7D23B48295',
            'clientRefId' => null,
            'paymentRefId' => 10012,
            'code' => 'd2e353189823079e1e4181772cff5292',
            'payedDate' => '2025-12-10 12:10:08',
            'payerWage' => 10,
            'businessWage' => 10,
            'gatewayAmount' => 1_020,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulReversalResponse(): array
    {
        return [
            'amount' => 1_000,
            'clientRefId' => null,
            'paymentRefId' => 10012,
            'code' => 'd2e353189823079e1e4181772cff5292',
            'payerWage' => 10,
            'gatewayAmount' => 1_020,
            'reversedDate' => '2025-12-10 12:10:08',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function failedResponse(?string $method = null): array
    {
        return [
            'type' => 'https://datatracker.ietf.org/doc/html/rfc7231#section-6.5.1',
            'title' => 'ValidationException',
            'status' => 400,
            'instance' => '/v3/pay',
            'paypingTraceId' => '0HN50ATIAS006:00000002',
            'metaData' => [
                'code' => 102,
                'errors' => [
                    ['message' => "مقدار فیلد 'آدرس بازگشت پذیرنده' اجباری است"],
                    ['message' => "مقدار فیلد 'شناسه ارجاع پذیرنده' اجباری است"],
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulCallback(): array
    {
        return [
            'status' => 1,
            'errorCode' => null,
            'data' => [
                'paymentCode' => 'd2e353189823079e1e4181772cff5292',
                'clientRefId' => '',
                'paymentRefId' => 123456,
                'amount' => 1000,
                'gatewayAmount' => 1020,
                'cardNumber' => '123456******4321',
                'cardHashPan' => '13464dasgfasdvad',

            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function failedCallback(): array
    {
        return [
            'status' => 0,
            'errorCode' => 102,
            'data' => [
                'paymentCode' => 'd2e353189823079e1e4181772cff5292',
                'clientRefId' => '',
                'amount' => 1000,
                'gatewayAmount' => 1020,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function gatewayPayload(): array
    {
        return [
            'payment_code' => 'd2e353189823079e1e4181772cff5292',
            'amount' => 1_000,
        ];
    }
}
