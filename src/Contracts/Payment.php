<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Contracts;

use AliYavari\IranPayment\Dtos\PaymentRedirectDto;

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
     * Check if payment creation was successfully.
     */
    public function successful(): bool;

    /**
     * Check if payment creation failed.
     */
    public function failed(): bool;

    /**
     * Get the error message if payment creation failed
     */
    public function error(): ?string;

    /**
     * Get the raw response from the gateway API.
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
}
