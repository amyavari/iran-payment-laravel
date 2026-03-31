<?php

declare(strict_types=1);

namespace AliYavari\IranPayment;

use AliYavari\IranPayment\Drivers\BehpardakhtDriver;
use AliYavari\IranPayment\Drivers\IdPayDriver;
use AliYavari\IranPayment\Drivers\SepDriver;
use AliYavari\IranPayment\Drivers\ZarinpalDriver;
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
            ->discoversMigrations()
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
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

        $this->app->bind(
            SepDriver::class,
            fn (): SepDriver => new SepDriver(...$this->configWithCamelCaseKeys('iran-payment.gateways.sep'))
        );

        $this->app->bind(
            ZarinpalDriver::class,
            fn (): ZarinpalDriver => new ZarinpalDriver(...$this->configWithCamelCaseKeys('iran-payment.gateways.zarinpal'))
        );

        $this->app->bind(
            IdPayDriver::class,
            fn (): IdPayDriver => new IdPayDriver(...$this->configWithCamelCaseKeys('iran-payment.gateways.id_pay'))
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
