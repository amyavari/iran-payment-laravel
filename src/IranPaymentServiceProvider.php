<?php

declare(strict_types=1);

namespace AliYavari\IranPayment;

use AliYavari\IranPayment\Contracts\Payment;
use AliYavari\IranPayment\Contracts\UniqueNumberGenerator;
use AliYavari\IranPayment\Drivers\BehpardakhtDriver;
use AliYavari\IranPayment\Drivers\IdpayDriver;
use AliYavari\IranPayment\Drivers\NextpayDriver;
use AliYavari\IranPayment\Drivers\PaypingDriver;
use AliYavari\IranPayment\Drivers\PepDriver;
use AliYavari\IranPayment\Drivers\SadadDriver;
use AliYavari\IranPayment\Drivers\SepDriver;
use AliYavari\IranPayment\Drivers\ZarinpalDriver;
use AliYavari\IranPayment\Drivers\ZibalDriver;
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
    /**
     * All supported gateways, with the driver they resolve to.
     *
     * @var array<string, array{class: class-string<Abstracts\Driver>, with_number_generator: bool}>
     */
    private const array DRIVERS = [
        'behpardakht' => ['class' => BehpardakhtDriver::class, 'with_number_generator' => true],
        'sep' => ['class' => SepDriver::class, 'with_number_generator' => true],
        'zarinpal' => ['class' => ZarinpalDriver::class, 'with_number_generator' => false],
        'idpay' => ['class' => IdpayDriver::class, 'with_number_generator' => true],
        'pep' => ['class' => PepDriver::class, 'with_number_generator' => true],
        'sadad' => ['class' => SadadDriver::class, 'with_number_generator' => true],
        'zibal' => ['class' => ZibalDriver::class, 'with_number_generator' => false],
        'payping' => ['class' => PaypingDriver::class, 'with_number_generator' => false],
        'nextpay' => ['class' => NextpayDriver::class, 'with_number_generator' => true],
    ];

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

        $this->app->singleton(PaymentManager::class, function (Application $app): PaymentManager {
            $manager = new PaymentManager($app);

            foreach (self::DRIVERS as $driver => ['class' => $class]) {
                $manager->extend($driver, fn (): Payment => $app->make($class));
            }

            return $manager;
        });

        foreach (self::DRIVERS as $driver => ['class' => $class, 'with_number_generator' => $withNumberGenerator]) {
            $this->app->bind($class, fn (): Payment => new $class(...$this->buildArguments($driver, $withNumberGenerator)));
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function buildArguments(string $driver, bool $withNumberGenerator): array
    {
        return $this->configWithCamelCaseKeys("iran-payment.gateways.{$driver}")
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
