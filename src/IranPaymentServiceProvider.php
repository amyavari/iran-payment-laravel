<?php

declare(strict_types=1);

namespace AliYavari\IranPayment;

use AliYavari\IranPayment\Contracts\UniqueNumberGenerator;
use AliYavari\IranPayment\Drivers\BehpardakhtDriver;
use AliYavari\IranPayment\Drivers\IdPayDriver;
use AliYavari\IranPayment\Drivers\PepDriver;
use AliYavari\IranPayment\Drivers\SepDriver;
use AliYavari\IranPayment\Drivers\ZarinpalDriver;
use AliYavari\IranPayment\Services\TimeBasedUniqueNumberGenerator;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
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
        $this->app->singleton(UniqueNumberGenerator::class, fn (): UniqueNumberGenerator => new TimeBasedUniqueNumberGenerator());

        $this->app->singleton(PaymentManager::class, fn (Application $app): PaymentManager => new PaymentManager($app));

        $this->app->bind(
            BehpardakhtDriver::class,
            fn (): BehpardakhtDriver => new BehpardakhtDriver(...$this->buildArguments('behpardakht'))
        );

        $this->app->bind(
            SepDriver::class,
            fn (): SepDriver => new SepDriver(...$this->buildArguments('sep'))
        );

        $this->app->bind(
            ZarinpalDriver::class,
            fn (): ZarinpalDriver => new ZarinpalDriver(...$this->buildArguments('zarinpal', withNumberGenerator: false))
        );

        $this->app->bind(
            IdPayDriver::class,
            fn (): IdPayDriver => new IdPayDriver(...$this->buildArguments('id_pay'))
        );

        $this->app->bind(
            PepDriver::class,
            fn (): PepDriver => new PepDriver(...$this->buildArguments('pep'))
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function buildArguments(string $gateway, bool $withNumberGenerator = true): array
    {
        return $this->configWithCamelCaseKeys("iran-payment.gateways.{$gateway}")
            ->when(
                $withNumberGenerator,
                fn (Collection $arguments): Collection => $arguments->merge([
                    'uniqueNumber' => $this->app->make(UniqueNumberGenerator::class),
                ])
            )
            ->all();
    }

    /**
     * @return Collection<string,mixed>
     */
    private function configWithCamelCaseKeys(string $key): Collection
    {
        return collect(config()->array($key))
            ->mapWithKeys(fn (mixed $value, string $key): array => [Str::camel($key) => $value]);
    }
}
