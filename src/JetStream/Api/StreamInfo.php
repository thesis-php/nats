<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
final readonly class StreamInfo
{
    /**
     * @param list<StreamSourceInfo> $sources
     */
    public function __construct(
        public StreamConfig $config,
        public \DateTimeImmutable $created,
        public StreamState $state,
        public \DateTimeImmutable $ts,
        public ?ClusterInfo $cluster = null,
        public ?StreamSourceInfo $mirror = null,
        public array $sources = [],
    ) {}
}
