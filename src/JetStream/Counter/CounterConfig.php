<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Counter;

use Thesis\Nats\JetStream\Api\StorageType;

/**
 * @api
 */
final readonly class CounterConfig
{
    /**
     * @param non-empty-string $name
     * @param int<1, 5> $replicas
     */
    public function __construct(
        public string $name,
        public ?string $description = null,
        public StorageType $storageType = StorageType::File,
        public int $replicas = 1,
    ) {}
}
