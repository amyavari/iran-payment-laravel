<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Helpers;

use AliYavari\IranPayment\Drivers\NextpayDriver;
use AliYavari\IranPayment\Facades\Payment;
use Illuminate\Support\Facades\Config;

final class NextpayHelper extends AbstractHelper
{
    /**
     * {@inheritdoc}
     */
    public static function setDriverConfigs(): void
    {
        Config::set('iran-payment.gateways.nextpay.callback_url', 'http://callback.test');
        Config::set('iran-payment.gateways.nextpay.api_key', 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
    }

    /**
     * {@inheritdoc}
     */
    public static function driver(): NextpayDriver
    {
        return Payment::gateway('nextpay');
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulCreationResponse(): array
    {
        return [
            'code' => -1,
            'trans_id' => 'f7c07568-c6d1-4bee-87b1-4a9e5ed2e4c1',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulVerificationResponse(): array
    {
        return [
            'code' => 0,
            'amount' => 1_000,
            'order_id' => '1234567890',
            'card_holder' => '5022-29**-****-5020',
            'customer_phone' => '09121234567',
            'Shaparak_Ref_Id' => '141196584609',
            'custom' => [],
            'created_at' => '1397-01-01 14:16:17',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulReversalResponse(): array
    {
        return [
            'code' => -90,
            'amount' => 1_000,
            'order_id' => '1234567890',
            'card_holder' => '5022-29**-****-5020',
            'customer_phone' => '09121234567',
            'custom' => [],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function failedResponse(?string $method = null): array
    {
        return [
            'code' => -2,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulCallback(): array
    {
        return [
            'trans_id' => 'f7c07568-c6d1-4bee-87b1-4a9e5ed2e4c1',
            'order_id' => '1234567890',
            'amount' => 1_000,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function failedCallback(): array
    {
        return self::successfulCallback();
    }

    /**
     * {@inheritdoc}
     */
    public static function gatewayPayload(): array
    {
        return [
            'order_id' => '1234567890',
            'transaction_id' => 'f7c07568-c6d1-4bee-87b1-4a9e5ed2e4c1',
            'amount' => 1_000,
        ];
    }
}
