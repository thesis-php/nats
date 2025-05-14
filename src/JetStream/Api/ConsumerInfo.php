<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class ConsumerInfo
{
    /**
     * @param non-empty-string $name
     * @param non-negative-int $numPending
     * @param ?list<PriorityGroupState> $priorityGroups
     */
    public function __construct(
        public string $streamName,
        public string $name,
        public \DateTimeImmutable $created,
        public ConsumerConfig $config,
        public SequenceInfo $delivered,
        public SequenceInfo $ackFloor,
        public int $numAckPending,
        public int $numRedelivered,
        public int $numWaiting,
        public int $numPending,
        public \DateTimeImmutable $ts,
        public ?ClusterInfo $cluster = null,
        public ?bool $pushBound = null,
        public ?array $priorityGroups = null,
        public ?bool $paused = null,
        public ?TimeSpan $pauseRemaining = null,
    ) {}
}
