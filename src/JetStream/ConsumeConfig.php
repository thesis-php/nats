<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class ConsumeConfig implements \JsonSerializable
{
    private const int DEFAULT_PULL_EXPIRES_SECS = 30;

    public TimeSpan $expires;

    /**
     * @param positive-int $batch
     * @param ?non-negative-int $maxBytes
     * @param ?non-empty-string $pinId
     * @param ?non-empty-string $group
     */
    public function __construct(
        ?TimeSpan $expires = null,
        public int $batch = 100,
        public ?int $maxBytes = null,
        public ?bool $noWait = null,
        public ?TimeSpan $heartbeat = null,
        public ?int $minPending = null,
        public ?int $minAckPending = null,
        public ?string $pinId = null,
        public ?string $group = null,
    ) {
        $this->expires = $expires ?? TimeSpan::fromSeconds(self::DEFAULT_PULL_EXPIRES_SECS);
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter(
            [
                'expires' => $this->expires->toNanoseconds(),
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
