<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Concerns;

use AliYavari\IranPayment\Models\Payment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Provides the payments relationship for a model.
 *
 * @phpstan-ignore trait.unused
 */
trait HasPayment
{
    /**
     * Get the payments that belong to this model
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }
}
