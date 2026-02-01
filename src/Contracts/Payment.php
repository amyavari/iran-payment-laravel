<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Contracts;

use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Models\Payment as PaymentModel;
use Illuminate\Database\Eloquent\Model;

/**
 * Public API for interacting with the amyavari/iran-payment-laravel package
 */
interface Payment
{
    /**
     * Create a new payment.
     */
    public function create(int $amount, ?string $description = null, string|int|null $phone = null): static;

    /**
     * Set the callback URL at runtime.
     */
    public function callbackUrl(string $callbackUrl): static;

    /**
     * Check whether the API request was successful.
     *
     * @throws \AliYavari\IranPayment\Exceptions\ApiIsNotCalledException
     */
    public function successful(): bool;

    /**
     * Check whether the API request failed.
     *
     * @throws \AliYavari\IranPayment\Exceptions\ApiIsNotCalledException
     */
    public function failed(): bool;

    /**
     * Get the error message returned by the API, if the request failed.
     *
     * @throws \AliYavari\IranPayment\Exceptions\ApiIsNotCalledException
     */
    public function error(): ?string;

    /**
     * Get the raw response from the gateway API.
     *
     * @throws \AliYavari\IranPayment\Exceptions\ApiIsNotCalledException
     */
    public function getRawResponse(): mixed;

    /**
     * Get the payment transaction ID.
     */
    public function getTransactionId(): ?string;

    /**
     * Get the payment gateway name.
     */
    public function getGateway(): string;

    /**
     * Get the gateway payload required for payment verification.
     *
     * @return array<string,mixed>|null
     */
    public function getGatewayPayload(): ?array;

    /**
     * Get the data required to redirect the user to the payment page.
     */
    public function getPaymentRedirectData(): ?PaymentRedirectDto;

    /**
     * Store the created payment in the database.
     */
    public function store(Model $payable): static;

    /**
     * Get the stored payment Eloquent model.
     */
    public function getModel(): ?PaymentModel;

    /**
     * Create a payment instance from gateway callback.
     *
     * @param  array<string,mixed>  $callbackPayload
     */
    public function fromCallback(array $callbackPayload): static;

    /**
     * Create a payment instance with no gateway callback.
     */
    public function noCallback(string $transactionId): static;

    /**
     * Get the reference number assigned to the transaction by the bank.
     */
    public function getRefNumber(): ?string;

    /**
     * Get user's card number used to pay.
     */
    public function getCardNumber(): ?string;

    /**
     * Verify the payment
     *
     * @param  array<string,mixed>|null  $gatewayPayload
     *
     * @throws \AliYavari\IranPayment\Exceptions\MissingVerificationPayloadException
     * @throws \AliYavari\IranPayment\Exceptions\InvalidCallbackDataException
     * @throws \AliYavari\IranPayment\Exceptions\PaymentAlreadyVerifiedException
     */
    public function verify(?array $gatewayPayload = null): static;

    /**
     * Settle the payment
     *
     * @throws \AliYavari\IranPayment\Exceptions\PaymentNotVerifiedException
     */
    public function settle(): static;

    /**
     * Reverse the payment
     *
     * @throws \AliYavari\IranPayment\Exceptions\PaymentNotVerifiedException
     */
    public function reverse(): static;

    /**
     * Enable automatic settlement after verification if the payment is successful.
     */
    public function autoSettle(bool $autoSettle = true): static;

    /**
     * Enable automatic reversal after verification if the payment fails.
     */
    public function autoReverse(bool $autoReverse = true): static;
}
