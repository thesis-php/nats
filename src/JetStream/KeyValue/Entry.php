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
     * @param non-negative-int $revision
     */
    public function __construct(
        public string $bucket,
        public string $key,
        public \DateTimeImmutable $created,
        public int $revision,
        public ?string $value = null,
        public int $delta = 0,
    ) {}
}
