<?php

declare(strict_types=1);

namespace Thesis\Nats;

/**
 * @api
 */
final readonly class SubscriptionStats
{
    /**
     * @param non-negative-int $pendingMessages received, but not yet passed to the handler
     * @param non-negative-int $pendingBytes the payload size of the pending messages
     * @param non-negative-int $delivered passed to the handler
     * @param non-negative-int $dropped dropped because the pending limits were exceeded
     */
    public function __construct(
        public int $pendingMessages,
        public int $pendingBytes,
        public int $delivered,
        public int $dropped,
    ) {}
}
