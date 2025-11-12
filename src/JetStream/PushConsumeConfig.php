<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

/**
 * @api
 */
final readonly class PushConsumeConfig
{
    private const int DEFAULT_MAX_MISSED_HEARTBEATS = 10;

    /**
     * @param positive-int $maxMissedHeartbeats
     */
    public function __construct(
        public int $maxMissedHeartbeats = self::DEFAULT_MAX_MISSED_HEARTBEATS,
    ) {}
}
