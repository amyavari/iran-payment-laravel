<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Concerns;

use AliYavari\IranPayment\Enums\ApiMethod;
use AliYavari\IranPayment\Enums\PaymentStatus;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\MissingGatewayPayloadException;
use AliYavari\IranPayment\Models\Payment;
use Illuminate\Support\Facades\Schema;

/**
 * @internal
 *
 * Provides logic for interacting with payment Eloquent models.
 *
 * Expects the consuming class to declare:
 * - private ?Payment $payment
 * - private Model $payable
 * - protected Collection<string,mixed> $callbackPayload
 * - private int $amount
 *
 * @phpstan-require-implements \AliYavari\IranPayment\Contracts\Payment
 */
trait ManagesModel
{
    /**
     * Stores the payment in the database
     */
    private function storePayment(): void
    {
        $this->payment = new Payment([
            'transaction_id' => $this->getTransactionId(),
            'amount' => $this->amount,
            'gateway' => $this->getGateway(),
            'gateway_payload' => $this->getGatewayPayload(),
            'status' => PaymentStatus::Pending,
            'owned_by_iran_payment' => true,
        ]);

        $this->payment->payable()->associate($this->payable)
            ->addRawResponse(ApiMethod::Create, $this->getRawResponse())
            ->save();
    }

    /**
     * Gets the payment record from the database
     */
    private function loadStoredPayment(): void
    {
        $this->payment = Payment::query()
            ->where('transaction_id', $this->getTransactionId())
            ->where('owned_by_iran_payment', true)
            ->first();
    }

    /**
     * Throws an exception if payments table doesn't exist
     *
     * @throws MissingGatewayPayloadException
     */
    private function ensureTableExists(): void
    {
        if (! Schema::hasColumns('payments', ['owned_by_iran_payment', 'transaction_id'])) {
            throw new MissingGatewayPayloadException('Gateway payload was not provided and the "payments" table does not exist.');
        }
    }

    /**
     * Throws an exception if payment record is not found
     *
     * @throws MissingGatewayPayloadException
     */
    private function ensurePaymentExists(): void
    {
        if (! $this->payment) {
            throw new MissingGatewayPayloadException('Gateway payload was not provided and no stored payment record was found.');
        }
    }

    /**
     * Update the payment record as failed due to a callback data mismatch.
     *
     * @param  array<string,mixed>  $payload
     */
    private function updatePaymentForInvalidCallback(InvalidCallbackDataException $exception, array $payload): void
    {
        $this->updatePaymentIfExists(ApiMethod::Verify, [
            'status' => PaymentStatus::Failed,
            'error' => $exception->getMessage(),
            'verified_at' => now(),
        ], [
            'callback' => $this->callbackPayload,
            'payload' => $payload,
        ]);
    }

    /**
     * Update the payment record after verification.
     */
    private function updatePaymentAfterVerification(): void
    {
        $this->updatePaymentIfExists(ApiMethod::Verify, [
            'status' => $this->successful() ? PaymentStatus::Successful : PaymentStatus::Failed,
            'error' => $this->error(),
            'ref_number' => $this->getRefNumber(),
            'card_number' => $this->getCardNumber(),
            'verified_at' => now(),
        ]);
    }

    /**
     * Updates the payment record with the provided data if it exists.
     *
     * @param  array<string,mixed>  $data
     */
    private function updatePaymentIfExists(ApiMethod $method, array $data, mixed $rawResponse = null): void
    {
        $this->payment?->fill($data)
            ->addRawResponse($method, $rawResponse ?? $this->getRawResponse())
            ->save();
    }

    /**
     * Update the payment record after reversal.
     */
    private function updatePaymentAfterReversal(): void
    {
        $this->updatePaymentIfExists(ApiMethod::Reverse, [
            'reversed_at' => now(),
        ]);
    }
}
