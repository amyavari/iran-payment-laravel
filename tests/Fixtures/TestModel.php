<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @internal
 *
 * Test model class used as a payable model to test storing.
 *
 * @property-read string $id
 */
final class TestModel extends Model
{
    use HasUuids;

    protected $guarded = [];
}
