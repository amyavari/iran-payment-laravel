<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Helpers;

use AliYavari\IranPayment\Drivers\ZarinpalDriver;
use AliYavari\IranPayment\Facades\Payment;
use Illuminate\Support\Facades\Config;

final class ZarinpalHelper extends AbstractHelper
{
    /**
     * {@inheritdoc}
     */
    public static function setDriverConfigs(): void
    {
        Config::set('iran-payment.gateways.zarinpal.callback_url', 'http://callback.test');
        Config::set('iran-payment.gateways.zarinpal.merchant_id', 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
    }

    /**
     * {@inheritdoc}
     */
    public static function driver(): ZarinpalDriver
    {
        return Payment::gateway('zarinpal');
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulCreationResponse(): array
    {
        return [
            'data' => [
                'code' => 100,
                'message' => 'Success',
                'authority' => 'A0000000000000000000000000000wwOGYpd',
                'fee_type' => 'Merchant',
                'fee' => 100,
            ],
            'errors' => [],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulVerificationResponse(): array
    {
        return [
            'data' => [
                'code' => 100,
                'message' => 'Verified',
                'card_hash' => '1EBE3EBEBE35C7EC0F8D6EE4F2F859107A87822CA179BC9528767EA7B5489B69',
                'card_pan' => '502229******5995',
                'ref_id' => 201,
                'fee_type' => 'Merchant',
                'fee' => 0,
            ],
            'errors' => [],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulReversalResponse(): array
    {
        return [
            'data' => [
                'code' => 100,
                'message' => 'Reversed',
            ],
            'errors' => [],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function failedResponse(?string $method = null): array
    {
        return [
            'data' => [],
            'errors' => [
                'message' => 'Terminal is not valid, please check merchant_id or ip address.',
                'code' => -10,
                'validations' => [],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulCallback(): array
    {
        return [
            'Authority' => 'A0000000000000000000000000000wwOGYpd',
            'Status' => 'OK',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function failedCallback(): array
    {
        return [
            'Authority' => 'A0000000000000000000000000000wwOGYpd',
            'Status' => 'NOK',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function gatewayPayload(): array
    {
        return [
            'authority' => 'A0000000000000000000000000000wwOGYpd',
            'amount' => '1000',
        ];
    }
}
