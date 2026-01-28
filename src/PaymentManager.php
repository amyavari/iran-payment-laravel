<?php

declare(strict_types=1);

namespace AliYavari\IranPayment;

use AliYavari\IranPayment\Contracts\Payment;
use AliYavari\IranPayment\Drivers\BehpardakhtDriver;
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

        /**
         * Make sure we get a new payment instance each time by removing it from the manager cache.
         */
        if ($this->mustBeFresh($driver)) {
            unset($this->drivers[$driver]);
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

    private function mustBeFresh(string $driver): bool
    {
        return (bool) $driver;
        // return isset($this->drivers[$driver])
        //     && ! $this->drivers[$driver] instanceof FakeDriver;
    }
}
