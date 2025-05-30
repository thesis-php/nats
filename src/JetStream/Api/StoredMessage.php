<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @internal
 */
final readonly class StoredMessage
{
    public function __construct(
        public string $subject,
        public int $seq,
        public \DateTimeImmutable $time,
        public ?string $hdrs = null,
        public ?string $data = null,
    ) {}
}
