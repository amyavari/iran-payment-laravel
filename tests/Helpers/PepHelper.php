<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Helpers;

use AliYavari\IranPayment\Drivers\PepDriver;
use AliYavari\IranPayment\Facades\Payment;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use ReflectionMethod;

final class PepHelper extends AbstractHelper
{
    /**
     * {@inheritdoc}
     */
    public static function setDriverConfigs(): void
    {
        Config::set('iran-payment.gateways.pep.callback_url', 'http://callback.test');
        Config::set('iran-payment.gateways.pep.base_url', 'https://base.url');
        Config::set('iran-payment.gateways.pep.terminal_number', '1234');
        Config::set('iran-payment.gateways.pep.username', 'username');
        Config::set('iran-payment.gateways.pep.password', 'password');
    }

    /**
     * {@inheritdoc}
     */
    public static function driver(): PepDriver
    {
        return Payment::gateway('pep');
    }

    /**
     * {@inheritdoc}
     */
    public static function callResolveToken(?PepDriver $driver = null): ?string
    {
        $resolveToken = new ReflectionMethod(PepDriver::class, 'resolveToken');

        return $resolveToken->invoke($driver ?? self::driver());
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulGetTokenResponse(): array
    {
        return [
            'resultMsg' => 'Successful',
            'resultCode' => 0,
            'token' => 'token',
            'username' => 'username',
            'firstName' => 'firstName',
            'lastName' => 'lastName',
            'userId' => '17',
            'roles' => [
                [
                    'authority' => 'mpg',
                ],
                [
                    'authority' => 'merchant',
                ],
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulCreationResponse(): array
    {
        return [
            'resultMsg' => 'Successful',
            'resultCode' => 0,
            'data' => [
                'urlId' => '8dcc5cd0ef7348548f8dc2ab29ebe11a7ad3eaad000000006217318',
                'url' => 'http://pep.shaparak.ir/8dcc5cd0ef7348548f8dc2ab29ebe11a7ad3eaad000000006217318',
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulVerificationResponse(): array
    {
        return [
            'resultMsg' => 'Successful',
            'resultCode' => 0,
            'data' => [
                'invoice' => '123456789012345',
                'referenceNumber' => '142514251425',
                'trackId' => '1234567',
                'maskedCardNumber' => '123456******1234',
                'hashedCardNumber' => 'ba6567ba6c9fc28e1434b838a91028a4250185fb6dd99d62c0392538a087c8be',
                'requestDate' => '2025-12-10 18:30:10.00000000',
                'amount' => 1_000,
            ],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulReversalResponse(): array
    {
        return [
            'resultMsg' => 'Successful',
            'resultCode' => 0,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function failedResponse(?string $method = null): array
    {
        return [
            'resultMsg' => 'Failure',
            'resultCode' => 1,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function successfulCallback(): array
    {
        return [
            'invoiceId' => 123456789012345,
            'status' => 'success',
            'referenceNumber' => 142536124562,
            'trackId' => 123456,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public static function failedCallback(): array
    {
        $successfulCallback = self::successfulCallback();

        return Arr::set($successfulCallback, 'status', 'failed');
    }

    /**
     * {@inheritdoc}
     */
    public static function gatewayPayload(): array
    {
        return [
            'invoice' => '123456789012345',
            'urlId' => '8dcc5cd0ef7348548f8dc2ab29ebe11a7ad3eaad000000006217318',
            'amount' => 1_000,
        ];
    }
}
