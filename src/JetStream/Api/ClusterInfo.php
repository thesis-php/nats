<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
final readonly class ClusterInfo
{
    /**
     * @param list<PeerInfo> $replicas
     */
    public function __construct(
        public ?string $name = null,
        public ?string $leader = null,
        public array $replicas = [],
    ) {}
}
