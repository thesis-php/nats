<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api\Result;

use Thesis\Nats\Exception\StreamNotFound;

/**
 * @internal
 */
final readonly class Error
{
    private const int STREAM_NOT_FOUND = 10059;

    public function __construct(
        public int $code,
        public int $errCode,
        public string $description,
    ) {}

    public function exception(): \Exception
    {
        return match ($this->errCode) {
            self::STREAM_NOT_FOUND => new StreamNotFound($this->description),
            default => new \RuntimeException("Nats error: '{$this->description} ({$this->errCode}) received'."),
        };
    }
}
