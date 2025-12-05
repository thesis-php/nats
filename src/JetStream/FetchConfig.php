<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class FetchConfig
{
    private const int DEFAULT_BATCH = 10_000;

    public TimeSpan $maxWait;

    public TimeSpan $heartbeat;

    public static function immediate(): self
    {
        return new self(noWait: true);
    }

    /**
     * @param positive-int $batch
     */
    public static function batch(
        int $batch,
        ?TimeSpan $maxWait = null,
        ?TimeSpan $heartbeat = null,
    ): self {
        return new self(
            batch: $batch,
            maxWait: $maxWait,
            heartbeat: $heartbeat,
        );
    }

    /**
     * @param positive-int $bytes
     */
    public static function bytes(
        int $bytes,
        ?TimeSpan $maxWait = null,
        ?TimeSpan $heartbeat = null,
    ): self {
        return new self(
            maxBytes: $bytes,
            maxWait: $maxWait,
            heartbeat: $heartbeat,
        );
    }

    /**
     * @param positive-int $batch
     * @param ?positive-int $maxBytes
     */
    private function __construct(
        public int $batch = self::DEFAULT_BATCH,
        public ?int $maxBytes = null,
        ?TimeSpan $maxWait = null,
        ?TimeSpan $heartbeat = null,
        public ?bool $noWait = null,
    ) {
        $maxWait ??= TimeSpan::fromSeconds(30);

        if ($this->noWait === true) {
            $maxWait = TimeSpan::fromSeconds(0);
        }

        $this->maxWait = $maxWait;
        $this->heartbeat = $heartbeat ?? (
            $this->maxWait->isGreaterThanOrEqualTo(TimeSpan::fromSeconds(10))
                ? TimeSpan::fromSeconds(5)
                : TimeSpan::fromSeconds(0)
        );

        if ($this->maxWait->isLessThan($this->heartbeat->mul(2))) {
            throw new \LogicException('Max wait time should be at least 2 times of the heartbeat.');
        }
    }
}
