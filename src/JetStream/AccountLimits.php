<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

/**
 * @api
 */
final readonly class AccountLimits
{
    /**
     * @param int $maxMemory is the maximum amount of memory available for this account
     * @param int $maxStorage is the maximum amount of disk storage available for this account
     * @param int $maxStreams is the maximum number of streams allowed for this account
     * @param int $maxConsumers is the maximum number of consumers allowed for this account
     */
    public function __construct(
        public int $maxMemory,
        public int $maxStorage,
        public int $maxStreams,
        public int $maxConsumers,
    ) {}
}
