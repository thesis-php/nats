<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

/**
 * @api
 */
final readonly class EndpointStats
{
    /**
     * @param non-empty-string $name
     * @param non-empty-string $subject
     * @param ?non-empty-string $queueGroup
     * @param non-negative-int $numRequests
     * @param non-negative-int $numErrors
     * @param non-negative-int $processingTime total processing time in nanoseconds
     * @param non-negative-int $averageProcessingTime average processing time in nanoseconds
     * @param mixed $data the value returned by {@see ServiceConfig::$statsHandler}, reported as JSON
     */
    public function __construct(
        public string $name,
        public string $subject,
        public ?string $queueGroup = null,
        public int $numRequests = 0,
        public int $numErrors = 0,
        public ?\Throwable $lastError = null,
        public int $processingTime = 0,
        public int $averageProcessingTime = 0,
        public mixed $data = null,
    ) {}
}
