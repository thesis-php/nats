<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

/**
 * @api
 */
final readonly class Tier
{
    /**
     * @param int $memory is the memory storage being used for Stream Message storage
     * @param int $storage the disk storage being used for Stream Message storage
     * @param int $reservedMemory is the number of bytes reserved for memory usage by this account on the server
     * @param int $reservedStorage is the number of bytes reserved for disk usage by this account on the server
     * @param int $streams is the number of streams currently defined for this account
     * @param int $consumers is the number of consumers currently defined for this account
     */
    public function __construct(
        public int $memory,
        public int $storage,
        public int $reservedMemory,
        public int $reservedStorage,
        public int $streams,
        public int $consumers,
        public AccountLimits $limits,
    ) {}
}
