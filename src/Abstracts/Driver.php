<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Abstracts;

use AliYavari\IranPayment\Contracts\Payment;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Enums\PaymentStatus;
use AliYavari\IranPayment\Exceptions\ApiIsNotCalledException;
use AliYavari\IranPayment\Exceptions\PaymentNotCreatedException;
use AliYavari\IranPayment\Models\Payment as PaymentModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

abstract class Driver implements Payment
{
    /**
     * Runtime user-defined callback URL.
     */
    private ?string $runtimeCallbackUrl = null;

    private ?string $calledApiMethod = null;

    private int $amount;

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
    final public function create(int $amount, ?string $description = null, string|int|null $phone = null): static
    {
        $this->setCalledApiMethod(__FUNCTION__);

        $this->amount = $this->toRial($amount);

        $this->createPayment($this->getCallbackUrl(), $this->amount, $description, $phone);

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
        $this->ensurePaymentCreationIsCalled();

        $this->whenSuccessful(function () use ($payable): void {
            $payment = new PaymentModel([
                'transaction_id' => $this->getTransactionId(),
                'amount' => $this->amount,
                'gateway' => $this->getGateway(),
                'gateway_payload' => $this->getGatewayPayload(),
                'status' => PaymentStatus::Pending,
            ]);

            $payment->payable()->associate($payable);

            $payment->addRawResponse('create', $this->getRawResponse());

            $payment->save();
        });

        return $this;
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
    private function ensureAbsoluteUrl(string $callbackUrl): string
    {
        return secure_url($callbackUrl);
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
     * throws and exception if the create payment API call has not been called.
     *
     * @throws PaymentNotCreatedException
     */
    private function ensurePaymentCreationIsCalled(): void
    {
        if ($this->calledApiMethod !== 'create') {
            throw new PaymentNotCreatedException('Payment must be created via the "create" method before storing.');
        }
    }
}
