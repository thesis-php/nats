<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\KeyValue;

/**
 * @api
 */
final readonly class Entry
{
    /**
     * @param non-empty-string $bucket
     * @param non-empty-string $key
     */
    public function __construct(
        public string $bucket,
        public string $key,
        public \DateTimeImmutable $created,
        public int $sequence,
        public ?string $value = null,
    ) {}
}
