<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Concerns;

use AliYavari\IranPayment\Enums\PaymentStatus;
use AliYavari\IranPayment\Exceptions\InvalidCallbackDataException;
use AliYavari\IranPayment\Exceptions\MissingVerificationPayloadException;
use AliYavari\IranPayment\Models\Payment;
use Illuminate\Support\Facades\Schema;

/**
 * @internal
 *
 * Provides logic for interacting with payment Eloquent models.
 *
 * Expects the consuming class to have the following properties:
 * - $payment
 * - $payable
 * - $callbackPayload
 * - $amount
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
            ->addRawResponse('create', $this->getRawResponse())
            ->save();
    }

    /**
     * Gets the payment record from the database
     */
    private function getStoredPayment(): void
    {
        $this->payment = Payment::query()
            ->where('transaction_id', $this->getTransactionId())
            ->where('owned_by_iran_payment', true)
            ->first();
    }

    /**
     * Throws an exception if payments table doesn't exist
     *
     * @throws MissingVerificationPayloadException
     */
    private function ensureTableExists(): void
    {
        if (! Schema::hasColumns('payments', ['owned_by_iran_payment', 'transaction_id'])) {
            throw new MissingVerificationPayloadException('Verification payload was not provided and the "payments" table does not exist.');
        }
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
            'callback' => $this->callbackPayload,
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

    /**
     * Update the payment record after settlement.
     */
    private function updatePaymentAfterSettlement(): void
    {
        $this->updatePaymentIfExists('settle', [
            'settled_at' => now(),
        ]);
    }

    /**
     * Update the payment record after reversal.
     */
    private function updatePaymentAfterReversal(): void
    {
        $this->updatePaymentIfExists('reverse', [
            'reversed_at' => now(),
        ]);
    }
}
