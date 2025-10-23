<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro\Internal;

use Thesis\Nats\Micro\EndpointStats;
use Thesis\Nats\Micro\Stats;

/**
 * @internal
 */
final readonly class StatsResponse implements \JsonSerializable
{
    public function __construct(
        private Stats $stats,
    ) {}

    /**
     * @return array<non-empty-string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => 'io.nats.micro.v1.stats_response',
            'name' => $this->stats->identity->name,
            'id' => $this->stats->identity->id,
            'version' => $this->stats->identity->version,
            'metadata' => $this->stats->identity->metadata,
            'started' => $this->stats->started->format(\DateTimeInterface::RFC3339),
            'endpoints' => array_map(
                static fn(EndpointStats $stats): array => array_filter([
                    'name' => $stats->name,
                    'subject' => $stats->subject,
                    'queue_group' => $stats->queueGroup,
                    'num_requests' => $stats->numRequests,
                    'num_errors' => $stats->numErrors,
                    'last_error' => $stats->lastError?->getMessage(),
                    'processing_time' => $stats->processingTime,
                    'average_processing_time' => $stats->averageProcessingTime,
                    'data' => $stats->data,
                ], static fn(mixed $value): bool => $value !== null),
                $this->stats->endpoints,
            ),
        ];
    }
}
