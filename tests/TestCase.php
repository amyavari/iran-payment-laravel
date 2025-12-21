<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests;

use AliYavari\IranPayment\IranPaymentServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * {@inheritdoc}
     */
    protected function getPackageProviders($app)
    {
        return [
            IranPaymentServiceProvider::class,
        ];
    }
}
