<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

use Thesis\Nats\JetStream\Api\Placement;
use Thesis\Nats\JetStream\Api\StorageType;
use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class StoreConfig
{
    /**
     * @param non-empty-string $store
     * @param int<1, 5> $replicas
     * @param array<string, string> $metadata
     */
    public function __construct(
        public string $store,
        public ?string $description = null,
        public ?TimeSpan $ttl = null,
        public int $maxBytes = -1,
        public StorageType $storageType = StorageType::File,
        public int $replicas = 1,
        public ?Placement $placement = null,
        public bool $compression = false,
        public array $metadata = [],
    ) {}
}
