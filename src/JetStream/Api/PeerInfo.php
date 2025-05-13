<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class PeerInfo
{
    /**
     * @param ?non-negative-int $lag
     */
    public function __construct(
        public string $name,
        public bool $current,
        public TimeSpan $active,
        public ?bool $offline = null,
        public ?int $lag = null,
    ) {}
}
