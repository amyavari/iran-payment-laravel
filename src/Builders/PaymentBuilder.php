<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Builders;

use AliYavari\IranPayment\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Builder;

/**
 * @internal
 *
 * Query builder class for `Payment` model
 */
final class PaymentBuilder extends Builder
{
    /**
     * Get only payments that are verified as successful.
     */
    public function successful(): Builder
    {
        return $this->where('status', PaymentStatus::Successful);
    }

    /**
     * Get only payments that are verified as failed.
     */
    public function failed(): Builder
    {
        return $this->where('status', PaymentStatus::Failed);
    }

    /**
     * Get only pending (unverified) payments.
     */
    public function pending(): Builder
    {
        return $this->where('status', PaymentStatus::Pending);
    }
}
