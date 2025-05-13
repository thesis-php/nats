<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

/**
 * @api
 */
final readonly class ApiStats
{
    /**
     * @param int $level is the API level for this account
     * @param non-negative-int $total is the total number of API calls
     * @param non-negative-int $errors is the total number of API errors
     * @param non-negative-int $inflight is the number of API calls currently in flight
     */
    public function __construct(
        public int $level,
        public int $total,
        public int $errors,
        public int $inflight = 0,
    ) {}
}
