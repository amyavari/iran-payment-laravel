<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Abstracts;

use AliYavari\IranPayment\Contracts\Payment;
use Illuminate\Support\Str;

abstract class Driver implements Payment
{
    /**
     * Runtime user-defined callback URL.
     */
    private ?string $runtimeCallbackUrl = null;

    /**
     * Get the driver's callback URL from configuration
     */
    abstract protected function driverCallbackUrl(): string;

    /**
     * Create a payment via the driver
     */
    abstract protected function createPayment(string $callbackUrl, int $amount, ?string $description = null, string|int|null $phone = null): void;

    /**
     * Get the payment gateway response status code.
     */
    abstract protected function getGatewayStatusCode(): string;

    /**
     * Get the payment gateway response status message.
     */
    abstract protected function getGatewayStatusMessage(): string;

    /**
     * {@inheritdoc}
     */
    abstract public function successful(): bool;

    /**
     * {@inheritdoc}
     */
    abstract public function getRawResponse(): mixed;

    /**
     * {@inheritdoc}
     */
    abstract public function getTransactionId(): ?string;

    /**
     * {@inheritdoc}
     */
    final public function create(int $amount, ?string $description = null, string|int|null $phone = null): static
    {
        $this->createPayment($this->getCallbackUrl(), $this->toRial($amount), $description, $phone);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    final public function callbackUrl(string $callbackUrl): static
    {
        $this->runtimeCallbackUrl = $callbackUrl;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    final public function failed(): bool
    {
        return ! $this->successful();
    }

    /**
     * {@inheritdoc}
     */
    final public function error(): ?string
    {
        if ($this->successful()) {
            return null;
        }

        return sprintf('کد %s- %s', $this->getGatewayStatusCode(), $this->getGatewayStatusMessage());
    }

    /**
     * Generate a random 15-digit, time-based transaction ID.
     */
    protected function generateUniqueTimeBaseNumber(): string
    {
        $randomNumber = random_int(1_000, 9_999);

        $currentTimeInMillisecond = now()->getTimestampMs();

        // The first `17` of timestamp doesn't add anything unique.
        // So, we remove it to have more space for random digits.
        return (string) Str::of((string) $currentTimeInMillisecond)
            ->after('17')
            ->append((string) $randomNumber);
    }

    /**
     * Convert the amount to Rial if the application currency is Toman.
     */
    private function toRial(int $amount): int
    {
        if (config()->string('iran-payment.currency') === 'Toman') {
            return $amount * 10;
        }

        return $amount;
    }

    /**
     * Get the final callback URL that the gateway should redirect to.
     */
    private function getCallbackUrl(): string
    {
        return $this->ensureAbsoluteUrl($this->runtimeCallbackUrl ?? $this->driverCallbackUrl());
    }

    /**
     * Prepends the application root if a relative path is provided.
     */
    private function ensureAbsoluteUrl(string $callbackUrl): string
    {
        return secure_url($callbackUrl);
    }
}
