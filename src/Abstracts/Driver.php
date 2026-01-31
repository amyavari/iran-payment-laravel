<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Abstracts;

use AliYavari\IranPayment\Concerns\ManagesModel;
use AliYavari\IranPayment\Contracts\Payment;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Exceptions\ApiIsNotCalledException;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\MissingVerificationPayloadException;
use AliYavari\IranPayment\Exceptions\PaymentAlreadyVerifiedException;
use AliYavari\IranPayment\Exceptions\PaymentNotVerifiedException;
use AliYavari\IranPayment\Models\Payment as PaymentModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @internal
 *
 * Abstract base class for payment gateway drivers.
 */
abstract class Driver implements Payment
{
    use ManagesModel;

    /**
     * Runtime user-defined callback URL.
     */
    private ?string $runtimeCallbackUrl = null;

    /**
     * Name of the API method invoked by the consumer.
     */
    private ?string $calledApiMethod = null;

    /**
     * Payment amount.
     */
    private int $amount;

    /**
     * Associated Eloquent payment model instance.
     */
    private ?PaymentModel $payment = null;

    /**
     * The Eloquent model this payment belongs to.
     */
    private Model $payable;

    /**
     * Callback data sent by the gateway after the user completes the payment.
     *
     * @var array<string,mixed>
     */
    private array $callbackPayload;

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
     * Check whether the API request was successful.
     */
    abstract protected function isSuccessful(): bool;

    /**
     * Get the raw response from the gateway API.
     */
    abstract protected function getGatewayRawResponse(): mixed;

    /**
     * Verify the payment via the driver
     *
     * @param  array<string,mixed>  $storedPayload
     *
     * @throws InvalidCallbackDataException
     */
    abstract protected function verifyPayment(array $storedPayload): void;

    /**
     * Settle the payment via the driver
     */
    abstract protected function settlePayment(): void;

    /**
     * Reverse the payment via the driver
     */
    abstract protected function reversePayment(): void;

    /**
     * Create new instance of gateway driver
     *
     * @param  array<string,mixed>  $callbackPayload
     */
    abstract protected function newFromCallback(array $callbackPayload): static;

    /**
     * {@inheritdoc}
     */
    abstract public function getTransactionId(): ?string;

    /**
     * {@inheritdoc}
     */
    abstract public function getGatewayPayload(): ?array;

    /**
     * {@inheritdoc}
     */
    abstract public function getPaymentRedirectData(): ?PaymentRedirectDto;

    /**
     * {@inheritdoc}
     */
    abstract public function getRefNumber(): ?string;

    /**
     * {@inheritdoc}
     */
    abstract public function getCardNumber(): ?string;

    /**
     * {@inheritdoc}
     */
    final public function create(int $amount, ?string $description = null, string|int|null $phone = null): static
    {
        $this->setCalledApiMethod(__FUNCTION__);

        $this->amount = $this->toRial($amount);

        $this->createPayment($this->getCallbackUrl(), $this->amount, $description, $phone);

        if ($this->shouldStorePayment()) {
            $this->storePayment();
        }

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
    final public function successful(): bool
    {
        $this->ensureApiIsCalled();

        return $this->isSuccessful();
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
        return $this->whenFailed(fn (): string => sprintf('کد %s- %s', $this->getGatewayStatusCode(), $this->getGatewayStatusMessage()));
    }

    /**
     * {@inheritdoc}
     */
    final public function getRawResponse(): mixed
    {
        $this->ensureApiIsCalled();

        return $this->getGatewayRawResponse();
    }

    /**
     * {@inheritdoc}
     */
    final public function getGateway(): string
    {
        return (string) Str::of(class_basename($this))->before('Driver')->snake();
    }

    /**
     * {@inheritdoc}
     */
    final public function store(Model $payable): static
    {
        $this->payable = $payable;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    final public function getModel(): ?PaymentModel
    {
        return $this->payment;
    }

    /**
     * {@inheritdoc}
     */
    final public function fromCallback(array $callbackPayload): static
    {
        $this->callbackPayload = $callbackPayload;

        return $this->newFromCallback($callbackPayload);
    }

    /**
     * {@inheritdoc}
     */
    final public function verify(?array $gatewayPayload = null): static
    {
        $this->setCalledApiMethod(__FUNCTION__);

        if (is_null($gatewayPayload)) {
            $gatewayPayload = $this->getStoredPayload();
        }

        $this->ensurePaymentIsNotVerified();

        try {
            $this->verifyPayment($gatewayPayload);
        } catch (InvalidCallbackDataException $invalidCallbackDataException) {
            $this->updatePaymentForInvalidCallback($invalidCallbackDataException, $gatewayPayload);

            throw $invalidCallbackDataException;
        }

        $this->updatePaymentAfterVerification();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    final public function settle(): static
    {
        $this->ensurePaymentIsVerifiedFor(__FUNCTION__);

        $this->settlePayment();

        $this->updatePaymentAfterSettlement();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    final public function reverse(): static
    {
        $this->ensurePaymentIsVerifiedFor(__FUNCTION__);

        $this->reversePayment();

        $this->updatePaymentAfterReversal();

        return $this;
    }

    /**
     * Executes the callback when the API call is successful.
     *
     * @return mixed|null
     */
    protected function whenSuccessful(callable $callback): mixed
    {
        if (! $this->successful()) {
            return null;
        }

        return call_user_func($callback);
    }

    /**
     * Executes the callback when the API call is successful.
     *
     * @return mixed|null
     */
    protected function whenFailed(callable $callback): mixed
    {
        if ($this->successful()) {
            return null;
        }

        return call_user_func($callback);
    }

    /**
     * Determine if the sandbox environment should be used.
     */
    protected function useSandbox(): bool
    {
        return config()->boolean('iran-payment.use_sandbox');
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
    private function ensureAbsoluteUrl(string $url): string
    {
        return secure_url($url);
    }

    /**
     * Records which API method has been called.
     */
    private function setCalledApiMethod(string $method): void
    {
        $this->calledApiMethod = $method;
    }

    /**
     * Throws an exception if an API method has not been called.
     *
     * @throws ApiIsNotCalledException
     */
    private function ensureApiIsCalled(): void
    {
        if (! $this->calledApiMethod) {
            throw new ApiIsNotCalledException('You must call an API method before checking its status.');
        }
    }

    /**
     * Determine whether the payment should be stored.
     */
    private function shouldStorePayment(): bool
    {
        return isset($this->payable) && $this->isSuccessful();
    }

    /**
     * Get gateway payload from stored payment record
     *
     * @return array<string,mixed>
     *
     * @throws MissingVerificationPayloadException
     */
    private function getStoredPayload(): array
    {
        $this->ensureTableExists();

        $this->getStoredPayment();

        $this->ensurePaymentExists();

        return $this->payment->gateway_payload;
    }

    /**
     * Throws an exception if payment is already verified.
     *
     * @throws PaymentAlreadyVerifiedException
     */
    private function ensurePaymentIsNotVerified(): void
    {
        if ($this->isVerified()) {
            throw new PaymentAlreadyVerifiedException(
                sprintf('Payment with transaction ID "%s" has already been verified.', $this->getTransactionId())
            );
        }
    }

    /**
     * Determine whether the payment has been verified.
     */
    private function isVerified(): bool
    {
        return (bool) $this->payment?->verified_at;
    }

    /**
     * Throws an exception if `verify` API method has not been called.
     *
     * @throws PaymentNotVerifiedException
     */
    private function ensurePaymentIsVerifiedFor(string $method): void
    {
        if ($this->calledApiMethod !== 'verify') {
            throw new PaymentNotVerifiedException(
                sprintf('You must verify the payment before running %s method.', $method)
            );
        }
    }
}
