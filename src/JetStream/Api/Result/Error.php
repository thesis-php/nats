<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api\Result;

use Thesis\Nats\Exception\ConsumerDoesNotExist;
use Thesis\Nats\Exception\ConsumerNotFound;
use Thesis\Nats\Exception\StreamNotFound;

/**
 * @internal
 */
final readonly class Error
{
    private const int STREAM_NOT_FOUND = 10059;
    private const int CONSUMER_NOT_FOUND = 10014;
    private const int CONSUMER_DOES_NOT_EXISTS = 10149;

    public function __construct(
        public int $code,
        public int $errCode,
        public string $description,
    ) {}

    public function exception(): \Exception
    {
        return match ($this->errCode) {
            self::STREAM_NOT_FOUND => new StreamNotFound($this->description),
            self::CONSUMER_NOT_FOUND => new ConsumerNotFound($this->description),
            self::CONSUMER_DOES_NOT_EXISTS => new ConsumerDoesNotExist($this->description),
            default => new \RuntimeException("Nats error: '{$this->description} ({$this->errCode}) received'."),
        };
    }
}
