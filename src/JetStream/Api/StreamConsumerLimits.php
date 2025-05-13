<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class StreamConsumerLimits implements \JsonSerializable
{
    public function __construct(
        public ?TimeSpan $inactiveThreshold = null,
        public ?int $maxAckPending = null,
    ) {}

    /**
     * @return array<non-empty-string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter(
            [
                'inactive_threshold' => $this->inactiveThreshold?->toNanoseconds(),
                'max_ack_pending' => $this->maxAckPending,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }
}
