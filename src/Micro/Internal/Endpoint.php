<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro\Internal;

use Thesis\Nats\Micro\EndpointInfo;
use Thesis\Nats\Micro\EndpointStats;

/**
 * @internal
 */
final readonly class Endpoint
{
    /**
     * @param non-empty-string $sid
     */
    public function __construct(
        public EndpointInfo $info,
        public EndpointStats $stats,
        public string $sid,
    ) {}
}
