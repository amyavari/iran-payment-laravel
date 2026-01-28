<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Builders;

use AliYavari\IranPayment\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * @internal
 *
 * Query builder class for `Payment` model
 *
 * @extends Builder<\AliYavari\IranPayment\Models\Payment>
 */
final class PaymentBuilder extends Builder
{
    /**
     * Get only payments that are verified as successful.
     */
    public function successful(): self
    {
        return $this->where('status', PaymentStatus::Successful);
    }

    /**
     * Get only payments that are verified as failed.
     */
    public function failed(): self
    {
        return $this->where('status', PaymentStatus::Failed);
    }

    /**
     * Get only pending (unverified) payments.
     */
    public function pending(): self
    {
        return $this->where('status', PaymentStatus::Pending);
    }
}
