<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Helpers;

use AliYavari\IranPayment\Drivers\ZibalDriver;
use AliYavari\IranPayment\Facades\Payment;
use Exception;
use Illuminate\Support\Facades\Config;

final class ZibalHelper extends AbstractHelper
{
    /**
     * {@inheritdoc}
     */
    public static function setDriverConfigs(): void
    {
        Config::set('iran-payment.gateways.zibal.callback_url', 'http://callback.test');
        Config::set('iran-payment.gateways.zibal.merchant', 'merchant');
    }

    /**
     * {@inheritdoc}
     */
    public static function driver(): ZibalDriver
    {
        return Payment::gateway('zibal');
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulCreationResponse(): array
    {
        return [
            'result' => 100,
            'message' => 'success',
            'trackId' => 15966442233311,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulVerificationResponse(): array
    {
        return [
            'result' => 100,
            'status' => 1,
            'paidAt' => '2025-03-25T23:43:01.053000',
            'amount' => 1_000,
            'refNumber' => 12312,
            'description' => '',
            'cardNumber' => '62741****44',
            'orderId' => '',
            'message' => 'success',
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
            'result' => 102,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulCallback(): array
    {
        return [
            'success' => '1',
            'status' => '2',
            'trackId' => '15966442233311',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function failedCallback(): array
    {
        return [
            'success' => '0',
            'status' => '3',
            'trackId' => '15966442233311',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function gatewayPayload(): array
    {
        return [
            'trackId' => 15966442233311,
            'amount' => 1_000,
        ];
    }
}
