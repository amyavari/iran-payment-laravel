<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Helpers;

use AliYavari\IranPayment\Drivers\SepDriver;
use AliYavari\IranPayment\Facades\Payment;
use Illuminate\Support\Facades\Config;

final class SepHelper extends AbstractHelper
{
    /**
     * {@inheritdoc}
     */
    public static function setDriverConfigs(): void
    {
        Config::set('iran-payment.gateways.sep.callback_url', 'http://callback.test');
        Config::set('iran-payment.gateways.sep.terminal_id', '1234');
    }

    /**
     * {@inheritdoc}
     */
    public static function driver(): SepDriver
    {
        return Payment::gateway('sep');
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulCreationResponse(): array
    {
        return [
            'status' => 1,
            'token' => '2c3c1fefac5a48geb9f9be7e445dd9b2',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulVerificationResponse(): array
    {
        return [
            'TransactionDetail' => [
                'RRN' => '227926981246',
                'RefNum' => '50',
                'MaskedPan' => '123456****1234',
                'HashedPan' => 'b96a14400c3a59249e87c300ecc06e5920327e70220213b5bbb7d7b2410f7e0d',
                'TerminalNumber' => 1234,
                'OrginalAmount' => 1_000,
                'AffectiveAmount' => 1_000,
                'StraceDate' => '2019-09-16 18:11:06',
                'StraceNo' => '100428',
            ],
            'ResultCode' => 0,
            'ResultDescription' => 'عملیات با موفقیت انجام شد',
            'Success' => true,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulReversalResponse(): array
    {
        return self::successfulVerificationResponse();
    }

    /**
     * {@inheritdoc}
     */
    public static function failedResponse(?string $method = null): array
    {
        return match ($method) {
            'create' => [
                'status' => -1,
                'errorCode' => '11',
                'errorDesc' => 'شماره کارت نامعتبر است',
            ],
            'verify', 'reverse' => [
                'Success' => false,
                'ResultCode' => -2,
                'ResultDescription' => 'تراکنش یافت نشد',
            ],
        };
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulCallback(): array
    {
        return [
            'MID' => '1234',
            'State' => 'OK',
            'Status' => 2,
            'RRN' => 123456789012,
            'RefNum' => 'Aht+dgVAEUDZ++54+qyrGzncrgA1kySE+NbxBUcNfbJafVj3f5',
            'ResNum' => '123456789012345',
            'TerminalId' => '1234',
            'TraceNo' => 123456,
            'Amount' => 1_000,
            'SecurePan' => '654321******4321',
            'HashedCardNumber' => '1234ABsab',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function failedCallback(): array
    {
        return [
            'MID' => '1234',
            'State' => 'CanceledByUser',
            'Status' => 1,
            'ResNum' => '123456789012345',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function gatewayPayload(): array
    {
        return [
            'resNum' => '123456789012345',
            'amount' => 1_000,
        ];
    }
}
