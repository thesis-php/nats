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
     * An object even without limits: the server rejects an empty JSON array, e.g. when a stream config it returned is sent back.
     */
    public function jsonSerialize(): \stdClass
    {
        return (object) array_filter(
            [
                'inactive_threshold' => $this->inactiveThreshold?->toNanoseconds(),
                'max_ack_pending' => $this->maxAckPending,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }
}
