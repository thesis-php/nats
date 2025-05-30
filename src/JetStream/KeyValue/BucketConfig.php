<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\KeyValue;

use Thesis\Nats\JetStream\Api\Placement;
use Thesis\Nats\JetStream\Api\RePublish;
use Thesis\Nats\JetStream\Api\StorageType;
use Thesis\Nats\JetStream\Api\StreamSource;
use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class BucketConfig
{
    /**
     * @param non-empty-string $bucket
     * @param int<1, 64> $history
     * @param int<1, 5> $replicas
     * @param ?list<StreamSource> $sources
     */
    public function __construct(
        public string $bucket,
        public ?string $description = null,
        public int $maxValueSize = -1,
        public int $history = 1,
        public ?TimeSpan $ttl = null,
        public int $maxBytes = -1,
        public StorageType $storageType = StorageType::File,
        public int $replicas = 1,
        public ?Placement $placement = null,
        public ?RePublish $rePublish = null,
        public ?StreamSource $mirror = null,
        public ?array $sources = null,
        public bool $compression = false,
        public ?TimeSpan $limitMarkerTtl = null,
    ) {}
}
