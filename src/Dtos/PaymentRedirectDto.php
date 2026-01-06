<?php

declare(strict_types=1);

namespace AliYavari\IranPayment\Dtos;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @internal
 *
 * @implements Arrayable<string,mixed>
 */
final readonly class PaymentRedirectDto implements Arrayable
{
    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $headers
     */
    public function __construct(
        public string $url,
        public string $method,
        public array $payload,
        public array $headers,
    ) {}

    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'method' => $this->method,
            'payload' => $this->payload,
            'headers' => $this->headers,
        ];
    }
}
