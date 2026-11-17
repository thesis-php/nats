<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Thesis\Nats\PendingLimits;

/**
 * @api
 */
final readonly class PushConsumeConfig
{
    private const int DEFAULT_MAX_MISSED_HEARTBEATS = 10;

    /**
     * @param positive-int $maxMissedHeartbeats
     * @param PendingLimits $pendingLimits the deliveries the handler has not taken yet, the ones beyond them are dropped
     */
    public function __construct(
        public int $maxMissedHeartbeats = self::DEFAULT_MAX_MISSED_HEARTBEATS,
        public PendingLimits $pendingLimits = new PendingLimits(),
    ) {}
}
