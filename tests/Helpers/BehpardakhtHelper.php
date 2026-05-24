<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Helpers;

use AliYavari\IranPayment\Drivers\BehpardakhtDriver;
use AliYavari\IranPayment\Facades\Payment;
use AliYavari\IranPayment\Facades\Soap;
use Illuminate\Support\Facades\Config;

final class BehpardakhtHelper extends AbstractHelper
{
    /**
     * {@inheritdoc}
     */
    public static function setDriverConfigs(): void
    {
        Config::set('iran-payment.gateways.behpardakht.callback_url', 'http://callback.test');
        Config::set('iran-payment.gateways.behpardakht.terminal_id', '1234');
        Config::set('iran-payment.gateways.behpardakht.username', 'username');
        Config::set('iran-payment.gateways.behpardakht.password', 'password');
    }

    /**
     * {@inheritdoc}
     */
    public static function fakeSoap(string $response = ''): void
    {
        Soap::fake($response);
    }

    /**
     * {@inheritdoc}
     */
    public static function driver(): BehpardakhtDriver
    {
        return Payment::gateway('behpardakht');
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulCreationResponse(): string
    {
        return '0,AF82041a2Bf6989c7fF9';
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulVerificationResponse(): string
    {
        return '0';
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulReversalResponse(): string
    {
        return self::successfulVerificationResponse();
    }

    /**
     * {@inheritdoc}
     */
    public static function failedResponse(?string $method = null): string
    {
        return '11';
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulCallback(): array
    {
        return [
            'RefId' => 'AF82041a2Bf6989c7fF9',
            'ResCode' => 0,
            'SaleOrderId' => 123456789012345,
            'SaleReferenceId' => 227926981246,
            'CardHolderInfo' => '1234-*-*-1234',
            'CardHolderPan' => '1234ABsab',
            'FinalAmount' => '1000',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function failedCallback(): array
    {
        return [
            'RefId' => 'AF82041a2Bf6989c7fF9',
            'ResCode' => 11,
            'SaleOrderId' => 123456789012345,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function gatewayPayload(): array
    {
        return [
            'orderId' => '123456789012345',
            'amount' => 1_000,
            'refId' => 'AF82041a2Bf6989c7fF9',
        ];
    }
}
