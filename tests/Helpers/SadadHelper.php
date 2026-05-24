<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Helpers;

use AliYavari\IranPayment\Drivers\SadadDriver;
use AliYavari\IranPayment\Facades\Payment;
use Exception;
use Illuminate\Support\Facades\Config;

final class SadadHelper extends AbstractHelper
{
    /**
     * {@inheritdoc}
     */
    public static function setDriverConfigs(): void
    {
        Config::set('iran-payment.gateways.sadad.callback_url', 'http://callback.test');
        Config::set('iran-payment.gateways.sadad.merchant_id', '1234');
        Config::set('iran-payment.gateways.sadad.terminal_id', '123456');
        Config::set('iran-payment.gateways.sadad.terminal_key', 'K34VFiiu0qar9xWICc9PPHYucWDzi02l'); // 2b7e151628aed2a6abf7158809cf4f3c762e7160f38b4da5
    }

    /**
     * {@inheritdoc}
     */
    public static function driver(): SadadDriver
    {
        return Payment::gateway('sadad');
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulCreationResponse(): array
    {
        return [
            'ResCode' => 0,
            'Token' => 'kjslflnvda13464sdv13a',
            'Description' => '',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulVerificationResponse(): array
    {
        return [
            'ResCode' => 0,
            'Amount' => 1_000,
            'Description' => '',
            'RetrivalRefNo' => '142514251425',
            'SystemTraceNo' => '4567',
            'OrderId' => '123456789012345',
            'TransactionDate' => '2025-12-01',
            'CardHolderFullName' => '',
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
        return match ($method) {
            'create' => [
                'ResCode' => 61,
            ],
            'verify' => [
                'ResCode' => -1,
            ],
            'reverse' => throw new Exception('Not Available'),
        };
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulCallback(): array
    {
        return [
            'ResCode' => 0,
            'OrderId' => 123456789012345,
            'SwitchResCod' => 1,
            'Token' => 'kjslflnvda13464sdv13a',
            'HashedCardNo' => 'ashdlf46463',
            'PrimaryAccNo' => '123456******1234',
            'CardHolderFullName' => '',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function failedCallback(): array
    {
        return [
            'ResCode' => -1,
            'OrderId' => 123456789012345,
            'SwitchResCod' => 1,
            'Token' => 'kjslflnvda13464sdv13a',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function gatewayPayload(): array
    {
        return [
            'orderId' => '123456789012345',
            'token' => 'kjslflnvda13464sdv13a',
            'amount' => 1_000,
        ];
    }
}
