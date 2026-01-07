<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Models;

use AliYavari\IranPayment\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property-read string $id
 * @property-read string $transaction_id
 * @property-read int|string $payable_id
 * @property-read string $payable_type
 * @property-read string $amount
 * @property-read string $gateway
 * @property-read PaymentStatus $status
 * @property-read array $gateway_payload
 * @property-read array $raw_responses
 * @property-read string|null $error
 * @property-read string|null $ref_number
 * @property-read string|null $card_number
 * @property-read Illuminate\Support\Carbon|null $verified_at
 * @property-read Illuminate\Support\Carbon|null $settled_at
 * @property-read Illuminate\Support\Carbon|null $reversed_at
 */
final class Payment extends Model
{
    use HasUuids;

    protected $guarded = [];

    /**
     * Get the payable model associated with this payment.
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Append a raw response to the model's raw responses log.
     */
    public function addRawResponse(string $method, mixed $response): static
    {
        $now = now()->format('YmdHis');

        if (is_null($this->raw_responses)) {
            $this->raw_responses = [];
        }

        $this->raw_responses = collect($this->raw_responses)
            ->merge([
                "{$method}_{$now}" => $response,
            ])
            ->all();

        return $this;
    }

    /**
     * @return array<string,mixed>
     */
    protected function casts(): array
    {
        return [
            'id' => 'string',
            'transaction_id' => 'string',
            'payable_id' => 'string',
            'payable_type' => 'string',
            'amount' => 'string',
            'gateway' => 'string',
            'status' => PaymentStatus::class,
            'gateway_payload' => 'array',
            'raw_responses' => 'array',
            'error' => 'string',
            'ref_number' => 'string',
            'card_number' => 'string',
            'verified_at' => 'datetime',
            'settled_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }
}
