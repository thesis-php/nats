<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class ConsumerConfig implements \JsonSerializable
{
    /**
     * @param ?non-negative-int $startSeq
     * @param ?list<TimeSpan> $backoff
     * @param ?non-negative-int $rateLimit
     * @param ?list<non-empty-string> $filterSubjects
     * @param ?array<string, string> $metadata
     * @param ?list<non-empty-string> $priorityGroups
     */
    public function __construct(
        public ?string $name = null,
        public ?string $durableName = null,
        public ?string $description = null,
        public DeliverPolicy $deliverPolicy = DeliverPolicy::All,
        public ?int $startSeq = null,
        public ?\DateTimeImmutable $startTime = null,
        public AckPolicy $ackPolicy = AckPolicy::Explicit,
        public ?TimeSpan $ackWait = null,
        public ?int $maxDeliver = null,
        public ?array $backoff = null,
        public ?string $filterSubject = null,
        public ReplayPolicy $replayPolicy = ReplayPolicy::Instant,
        public ?int $rateLimit = null,
        public ?string $sampleFrequency = null,
        public ?int $maxWaiting = null,
        public ?int $maxAckPending = null,
        public ?bool $headersOnly = null,
        public ?int $maxRequestBatch = null,
        public ?TimeSpan $maxRequestExpires = null,
        public ?int $maxRequestMaxBytes = null,
        public ?TimeSpan $inactiveThreshold = null,
        public ?int $replicas = null,
        public ?bool $memoryStorage = null,
        public ?array $filterSubjects = null,
        public ?array $metadata = null,
        public ?\DateTimeImmutable $pauseUntil = null,
        public ?PriorityPolicy $priorityPolicy = null,
        public ?TimeSpan $priorityTimeout = null,
        public ?array $priorityGroups = null,
    ) {}

    /**
     * @return array<non-empty-string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter(
            [
                'name' => $this->name,
                'durable_name' => $this->durableName,
                'description' => $this->description,
                'deliver_policy' => $this->deliverPolicy,
                'opt_start_seq' => $this->startSeq,
                'opt_start_time' => $this->startTime?->format(\DateTimeInterface::RFC3339),
                'ack_policy' => $this->ackPolicy,
                'ack_wait' => $this->ackWait?->toNanoseconds(),
                'max_deliver' => $this->maxDeliver,
                'backoff' => $this->backoff !== null ? array_map(
                    static fn(TimeSpan $span): int => $span->toNanoseconds(),
                    $this->backoff,
                ) : null,
                'filter_subject' => $this->filterSubject,
                'replay_policy' => $this->replayPolicy,
                'rate_limit_bps' => $this->rateLimit,
                'sample_freq' => $this->sampleFrequency,
                'max_waiting' => $this->maxWaiting,
                'max_ack_pending' => $this->maxAckPending,
                'headers_only' => $this->headersOnly,
                'max_batch' => $this->maxRequestBatch,
                'max_expires' => $this->maxRequestExpires?->toNanoseconds(),
                'max_bytes' => $this->maxRequestMaxBytes,
                'inactive_threshold' => $this->inactiveThreshold?->toNanoseconds(),
                'num_replicas' => $this->replicas,
                'mem_storage' => $this->memoryStorage,
                'filter_subjects' => $this->filterSubjects,
                'metadata' => $this->metadata,
                'pause_until' => $this->pauseUntil?->format(\DateTimeInterface::RFC3339),
                'priority_policy' => $this->priorityPolicy,
                'priority_timeout' => $this->priorityTimeout?->toNanoseconds(),
                'priority_groups' => $this->priorityGroups,
            ],
            static fn(mixed $value): bool => $value !== null,
        );
    }
}
