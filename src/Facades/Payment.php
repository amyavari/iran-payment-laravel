<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Facades;

use AliYavari\IranPayment\Drivers\FakeDriver;
use AliYavari\IranPayment\PaymentManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \AliYavari\IranPayment\Contracts\Payment gateway(string $gateway) Get a gateway driver instance
 * @method static \AliYavari\IranPayment\Contracts\Payment callbackUrl(string $callbackUrl) Set the callback URL at runtime.
 * @method static \AliYavari\IranPayment\Contracts\Payment store(\Illuminate\Database\Eloquent\Model $payable) Store the created payment in the database.
 * @method static \AliYavari\IranPayment\Contracts\Payment create(int $amount, ?string $description = null, string|int|null $phone = null) Create a new payment.
 * @method static \AliYavari\IranPayment\Contracts\Payment fromCallback(array<string,mixed> $callbackPayload) Specify that verification should use gateway callback data.
 * @method static \AliYavari\IranPayment\Contracts\Payment noCallback(string $transactionId) Specify that verification should proceed without gateway callback data.
 * @method static \AliYavari\IranPayment\Contracts\Payment autoSettle(bool $autoSettle = true) Enable automatic settlement after verification if the payment is successful.
 * @method static \AliYavari\IranPayment\Contracts\Payment autoReverse(bool $autoReverse = true) Enable automatic reversal after verification if the payment fails.
 *
 * @see \AliYavari\IranPayment\Contracts\Payment
 */
final class Payment extends Facade
{
    /**
     * Fakes payment calls for testing purposes.
     */
    public static function fake(?string $gateway = null): FakeDriver
    {
        $paymentManager = self::getFacadeRoot();

        $gateway ??= $paymentManager->getDefaultDriver();

        $fakeDriver = self::$app->make(FakeDriver::class, ['gateway' => $gateway]);

        $paymentManager->extend($gateway, fn () => $fakeDriver);

        return $fakeDriver;
    }

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return PaymentManager::class;
    }
}
