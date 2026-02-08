<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Models;

use AliYavari\IranPayment\Builders\PaymentBuilder;
use AliYavari\IranPayment\Contracts\Payment as PaymentInterface;
use AliYavari\IranPayment\Enums\PaymentStatus;
use AliYavari\IranPayment\Facades\Payment as PaymentFacade;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Override;

/**
 * @property-read string $id
 * @property-read string $transaction_id
 * @property-read int|string $payable_id
 * @property-read string $payable_type
 * @property-read string $amount (in Rial)
 * @property-read string $gateway
 * @property-read PaymentStatus $status
 * @property-read array<string,mixed> $gateway_payload
 * @property-read array<string,mixed> $raw_responses
 * @property-read string|null $error
 * @property-read string|null $ref_number
 * @property-read string|null $card_number
 * @property-read \Illuminate\Support\Carbon|null $verified_at
 * @property-read \Illuminate\Support\Carbon|null $settled_at
 * @property-read \Illuminate\Support\Carbon|null $reversed_at
 * @property-read bool $owned_by_iran_payment
 */
final class Payment extends Model
{
    use HasUuids;

    protected $guarded = [];

    /**
     * Get the payable model associated with this payment.
     *
     * @return MorphTo<Model, $this>
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

        $this->fill([
            'raw_responses' => collect($this->raw_responses)
                ->merge([
                    "{$method}_{$now}" => $response,
                ]),
        ]);

        return $this;
    }

    /**
     * Create the gateway payment instance from this payment model.
     */
    public function toGatewayPayment(): PaymentInterface
    {
        return PaymentFacade::gateway($this->gateway)->noCallback($this->transaction_id);
    }

    /**
     * {@inheritdoc}
     */
    #[Override]
    public function newEloquentBuilder($query): PaymentBuilder
    {
        return new PaymentBuilder($query);
    }

    /**
     * @return array<string,mixed>
     */
    #[Override]
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
            'owned_by_iran_payment' => 'bool',
        ];
    }
}
