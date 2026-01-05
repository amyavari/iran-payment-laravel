<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests;

use AliYavari\IranPayment\Facades\Soap;
use AliYavari\IranPayment\IranPaymentServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Override;

abstract class TestCase extends Orchestra
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->preventStrayRequests();
    }

    /**
     * {@inheritdoc}
     */
    protected function getPackageProviders($app)
    {
        return [
            IranPaymentServiceProvider::class,
        ];
    }

    /**
     * Prevents any request is not faked.
     */
    private function preventStrayRequests(): void
    {
        Soap::preventStrayRequests();
        Http::preventStrayRequests();
    }
}
