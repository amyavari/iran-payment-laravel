<?php

declare(strict_types=1);

namespace AliYavari\IranPayment;

use AliYavari\IranPayment\Contracts\Payment;
use AliYavari\IranPayment\Drivers\BehpardakhtDriver;
use AliYavari\IranPayment\Drivers\FakeDriver;
use Illuminate\Support\Arr;
use Illuminate\Support\Manager;
use Override;

/**
 * @internal
 */
final class PaymentManager extends Manager
{
    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): ?string
    {
        return $this->config->get('iran-payment.default');
    }

    /**
     * {@inheritdoc}
     */
    #[Override]
    public function driver($driver = null): Payment
    {
        $driver ??= $this->getDefaultDriver();

        if ($this->shouldBeImmutable($driver)) {
            Arr::forget($this->drivers, $driver);
        }

        return parent::driver($driver);
    }

    /**
     * Get a gateway driver instance
     */
    public function gateway(string $gateway): Payment
    {
        return $this->driver($gateway);
    }

    protected function createBehpardakhtDriver(): BehpardakhtDriver
    {
        return $this->container->make(BehpardakhtDriver::class);
    }

    private function shouldBeImmutable(string $driver): bool
    {
        return ! Arr::get($this->drivers, $driver) instanceof FakeDriver;
    }
}
