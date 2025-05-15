<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class PullRequest implements \JsonSerializable
{
    /**
     * @param ?non-negative-int $batch
     * @param ?non-negative-int $maxBytes
     * @param ?non-empty-string $pinId
     * @param ?non-empty-string $group
     */
    public function __construct(
        private ?TimeSpan $expires = null,
        private ?int $batch = null,
        private ?int $maxBytes = null,
        private ?bool $noWait = null,
        private ?TimeSpan $heartbeat = null,
        private ?int $minPending = null,
        private ?int $minAckPending = null,
        private ?string $pinId = null,
        private ?string $group = null,
    ) {}

    /**
     * @return array<non-empty-string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter(
            [
                'expires' => $this->expires?->toNanoseconds(),
                'batch' => $this->batch,
                'max_bytes' => $this->maxBytes,
                'no_wait' => $this->noWait,
                'heartbeat' => $this->heartbeat?->toNanoseconds(),
                'min_pending' => $this->minPending,
                'min_ack_pending' => $this->minAckPending,
                'pin_id' => $this->pinId,
                'group' => $this->group,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }
}
