<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

use Thesis\Time\TimeSpan;

/**
 * @internal
 */
final class EndpointStats
{
    /**
     * @param non-empty-string $name
     * @param non-empty-string $subject
     * @param ?non-empty-string $queueGroup
     * @param non-negative-int $numRequests
     * @param non-negative-int $numErrors
     */
    public function __construct(
        public string $name,
        public string $subject,
        public ?string $queueGroup = null,
        public int $numRequests = 0,
        public int $numErrors = 0,
        public ?\Throwable $lastError = null,
        public TimeSpan $processingTime = new TimeSpan(),
        public TimeSpan $averageProcessingTime = new TimeSpan(),
        public ?string $data = null,
    ) {}
}
