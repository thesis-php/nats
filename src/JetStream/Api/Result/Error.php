<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api\Result;

/**
 * @internal
 */
final readonly class Error
{
    public function __construct(
        public int $code,
        public int $errCode,
        public string $description,
    ) {}

    public function throw(): never
    {
        throw new \RuntimeException("Nats error {$this->description}({$this->code}) received.");
    }
}
