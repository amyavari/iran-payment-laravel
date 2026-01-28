<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Abstracts;

use AliYavari\IranPayment\Contracts\Payment;
use AliYavari\IranPayment\Dtos\PaymentRedirectDto;
use AliYavari\IranPayment\Enums\PaymentStatus;
use AliYavari\IranPayment\Exceptions\ApiIsNotCalledException;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\MissingVerificationPayloadException;
use AliYavari\IranPayment\Exceptions\PaymentNotCreatedException;
use AliYavari\IranPayment\Models\Payment as PaymentModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

abstract class Driver implements Payment
{
    /**
     * Runtime user-defined callback URL.
     */
    private ?string $runtimeCallbackUrl = null;

    private ?string $calledApiMethod = null;

    private int $amount;

    private ?PaymentModel $payment = null;

    /**
     * @var array<string,mixed>
     */
    private array $callbackData;

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
     * @param  array<string,mixed>  $payload
     *
     * @throws InvalidCallbackDataException
     */
    abstract protected function verifyPayment(array $payload): void;

    /**
     * Create new instance of gateway driver
     *
     * @param  array<string,mixed>  $callbackData
     */
    abstract protected function newFromCallback(array $callbackData): static;

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
                'owned_by_iran_payment' => true,
            ]);

            $payment->payable()->associate($payable);

            $payment->addRawResponse('create', $this->getRawResponse());

            $payment->save();
        });

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    final public function fromCallback(array $callbackData): static
    {
        $this->callbackData = $callbackData;

        return $this->newFromCallback($callbackData);
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
     * Throws an exception if payments table doesn't exist
     *
     * @throws MissingVerificationPayloadException
     */
    private function ensureTableExists(): void
    {
        if (! Schema::hasColumn('payments', 'owned_by_iran_payment')) {
            throw new MissingVerificationPayloadException('Verification payload was not provided and the "payments" table does not exist.');
        }
    }

    /**
     * Gets the payment record from the database
     */
    private function getStoredPayment(): void
    {
        $this->payment = PaymentModel::query()
            ->where('transaction_id', $this->getTransactionId())
            ->where('owned_by_iran_payment', true)
            ->first();
    }

    /**
     * Throws an exception if payment record is not found
     *
     * @throws MissingVerificationPayloadException
     */
    private function ensurePaymentExists(): void
    {
        if (! $this->payment) {
            throw new MissingVerificationPayloadException('Verification payload was not provided and no stored payment record was found.');
        }
    }

    /**
     * Update the payment record as failed due to a callback data mismatch.
     *
     * @param  array<string,mixed>  $payload
     */
    private function updatePaymentForInvalidCallback(InvalidCallbackDataException $exception, array $payload): void
    {
        $this->updatePaymentIfExists('verify', [
            'status' => PaymentStatus::Failed,
            'error' => $exception->getMessage(),
            'verified_at' => now(),
        ], [
            'callback' => $this->callbackData,
            'payload' => $payload,
        ]);
    }

    /**
     * Update the payment record after verification.
     */
    private function updatePaymentAfterVerification(): void
    {
        $this->updatePaymentIfExists('verify', [
            'status' => $this->successful() ? PaymentStatus::Successful : PaymentStatus::Failed,
            'error' => $this->error(),
            'verified_at' => now(),
        ]);
    }

    /**
     * Updates the payment record with the provided data if it exists.
     *
     * @param  array<string,mixed>  $data
     */
    private function updatePaymentIfExists(string $method, array $data, mixed $rawResponse = null): void
    {
        $this->payment?->fill($data)
            ->addRawResponse($method, $rawResponse ?? $this->getRawResponse())
            ->save();
    }
}
