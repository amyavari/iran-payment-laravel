<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Tests;

use AliYavari\IranPayment\Facades\Soap;
use AliYavari\IranPayment\IranPaymentServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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
     * {@inheritdoc}
     */
    protected function defineEnvironment($app)
    {
        config()->set('database.default', 'testing');

        $migrations = File::allFiles(__DIR__.'/../database/migrations');

        foreach ($migrations as $migration) {
            (include $migration->getRealPath())->up();
        }

        $this->migrateTestModel();
    }

    /**
     * Prevents any request is not faked.
     */
    private function preventStrayRequests(): void
    {
        Soap::preventStrayRequests();
        Http::preventStrayRequests();
    }

    private function migrateTestModel(): void
    {
        Schema::create('test_models', function (Blueprint $table): void {
            $table->uuid('id');
            $table->timestamps();
        });
    }
}
