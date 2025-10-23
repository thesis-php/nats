<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro\Internal;

use Thesis\Nats\Micro\EndpointStats;
use Thesis\Nats\Micro\ServiceIdentity;

/**
 * @internal
 */
final readonly class Stats implements \JsonSerializable
{
    /**
     * @param list<EndpointStats> $endpoints
     */
    public function __construct(
        public ServiceIdentity $identity,
        public \DateTimeImmutable $started,
        public array $endpoints = [],
    ) {}

    /**
     * @return array<non-empty-string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => 'io.nats.micro.v1.stats_response',
            'name' => $this->identity->name,
            'id' => $this->identity->id,
            'version' => $this->identity->version,
            'metadata' => $this->identity->metadata,
            'started' => $this->started->format(\DateTimeInterface::RFC3339),
            'endpoints' => array_map(
                static fn (EndpointStats $stats): array => array_filter([
                    'name' => $stats->name,
                    'subject' => $stats->subject,
                    'queue_group' => $stats->queueGroup,
                    'num_requests' => $stats->numRequests,
                    'num_errors' => $stats->numErrors,
                    'last_error' => $stats->lastError?->getMessage(),
                    'processing_time' => $stats->processingTime,
                    'average_processing_time' => $stats->averageProcessingTime,
                    'data' => $stats->data,
                ], static fn (mixed $value): bool => $value !== null),
                $this->endpoints,
            ),
        ];
    }
}
