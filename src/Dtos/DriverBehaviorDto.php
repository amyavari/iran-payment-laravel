<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Dtos;

/**
 * @internal
 */
final readonly class DriverBehaviorDto
{
    public function __construct(
        public bool $successful = true,
        public string $errorCode = '',
        public string $errorMessage = '',
        public mixed $rawResponse = '',
        public ?string $exceptionMessage = null,
    ) {}
}
