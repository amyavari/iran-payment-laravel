<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Helpers;

use AliYavari\IranPayment\Drivers\IdpayDriver;
use AliYavari\IranPayment\Facades\Payment;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;

final class IdpayHelper extends AbstractHelper
{
    /**
     * {@inheritdoc}
     */
    public static function setDriverConfigs(): void
    {
        Config::set('iran-payment.gateways.idpay.callback_url', 'http://callback.test');
        Config::set('iran-payment.gateways.idpay.api_key', 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
    }

    /**
     * {@inheritdoc}
     */
    public static function driver(): IdpayDriver
    {
        return Payment::gateway('idpay');
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulCreationResponse(): array
    {
        return [
            'id' => 'd2e353189823079e1e4181772cff5292',
            'link' => 'https://idpay.ir/p/ws-sandbox/d2e353189823079e1e4181772cff5292',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulVerificationResponse(): array
    {
        return [
            'status' => '100',
            'track_id' => '10012',
            'id' => 'd2e353189823079e1e4181772cff5292',
            'order_id' => '101',
            'amount' => '1000',
            'date' => '1546288200',
            'payment' => [
                'track_id' => '888001',
                'amount' => '1000',
                'card_no' => '123456******1234',
                'hashed_card_no' => 'E59FA6241C94B8836E3D03120DF33E80FD988888BBA0A122240C2E7D23B48295',
                'date' => '1546288500',
            ],
            'verify' => [
                'date' => '1546288800',
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulReversalResponse(): array
    {
        throw new Exception('Not implemented');
    }

    /**
     * {@inheritdoc}
     */
    public static function failedResponse(?string $method = null): array
    {
        return [
            'error_code' => 32,
            'error_message' => 'شماره سفارش `order_id` نباید خالی باشد.',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulCallback(): array
    {
        return [
            'status' => 100,
            'track_id' => 123456,
            'id' => 'd2e353189823079e1e4181772cff5292',
            'order_id' => '123456789012345',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function failedCallback(): array
    {
        $successfulCallback = self::successfulCallback();

        return Arr::set($successfulCallback, 'status', 1);
    }

    /**
     * {@inheritdoc}
     */
    public static function gatewayPayload(): array
    {
        return [
            'order_id' => '123456789012345',
            'id' => 'd2e353189823079e1e4181772cff5292',
            'amount' => '1000',
        ];
    }
}
