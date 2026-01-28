<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Facades;

use AliYavari\IranPayment\PaymentManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \AliYavari\IranPayment\Contracts\Payment create(int $amount, ?string $description = null, string|int|null $phone = null) Create a new payment.
 * @method static \AliYavari\IranPayment\Contracts\Payment callbackUrl(string $callbackUrl) Set the callback URL at runtime.
 * @method static bool successful() Check whether the API request was successful.
 * @method static bool failed() Check whether the API request failed.
 * @method static ?string error() Get the error message returned by the API, if the request failed.
 * @method static mixed getRawResponse() Get the raw response from the gateway API.
 * @method static ?string getTransactionId() Get the payment transaction ID.
 * @method static string getGateway() Get the payment gateway name.
 * @method static array<string,mixed>|null getGatewayPayload() Get the gateway payload required for payment verification.
 * @method static \AliYavari\IranPayment\Dtos\PaymentRedirectDto|null getPaymentRedirectData() Get the data required to redirect the user to the payment page.
 * @method static \AliYavari\IranPayment\Contracts\Payment store(\Illuminate\Database\Eloquent\Model $payable) Store the created payment in the database.
 * @method static \AliYavari\IranPayment\Models\Payment|null getModel() Get the stored payment Eloquent model.
 * @method static \AliYavari\IranPayment\Contracts\Payment fromCallback(array<string,mixed> $callbackPayload) Create a payment instance from gateway callback.
 * @method static ?string getRefNumber() Get the reference number assigned to the transaction by the bank.
 * @method static ?string getCardNumber() Get user's card number used to pay.
 * @method static \AliYavari\IranPayment\Contracts\Payment verify(array<string,mixed>|null $gatewayPayload = null) Verify the payment
 * @method static \AliYavari\IranPayment\Contracts\Payment gateway(string $gateway) Get a gateway driver instance
 *
 * @see \AliYavari\IranPayment\Contracts\Payment
 */
final class Payment extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return PaymentManager::class;
    }
}
