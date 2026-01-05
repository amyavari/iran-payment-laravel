<?php

declare(strict_types=1);

namespace AliYavari\IranPayment;

use AliYavari\IranPayment\Drivers\BehpardakhtDriver;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * @internal
 */
final class IranPaymentServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('iran-payment')
            ->hasConfigFile()
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->askToStarRepoOnGitHub('amyavari/iran-payment-laravel');
            });
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PaymentManager::class, fn (Application $app): PaymentManager => new PaymentManager($app));

        $this->app->bind(
            BehpardakhtDriver::class,
            fn (): BehpardakhtDriver => new BehpardakhtDriver(...$this->configWithCamelCaseKeys('iran-payment.gateways.behpardakht'))
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function configWithCamelCaseKeys(string $key): array
    {
        return collect(config()->array($key))
            ->mapWithKeys(fn (mixed $value, string $key): array => [Str::camel($key) => $value])
            ->all();
    }
}
