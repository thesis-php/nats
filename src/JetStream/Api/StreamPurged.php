<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
final readonly class StreamPurged
{
    /**
     * @param non-negative-int $purged
     */
    public function __construct(
        public bool $success = true,
        public int $purged = 0,
    ) {}
}
