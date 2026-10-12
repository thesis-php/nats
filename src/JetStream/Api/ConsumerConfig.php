<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

use Thesis\Nats\Exception\InvalidName;
use Thesis\Nats\Exception\InvalidSubject;
use Thesis\Nats\Internal\Validation\Validate;
use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class ConsumerConfig implements \JsonSerializable
{
    /**
     * @param ?non-empty-string $deliverSubject
     * @param ?non-empty-string $deliverGroup
     * @param ?non-negative-int $startSeq
     * @param ?list<TimeSpan> $backoff
     * @param ?non-negative-int $rateLimit
     * @param ?list<non-empty-string> $filterSubjects
     * @param ?array<string, string> $metadata
     * @param ?list<non-empty-string> $priorityGroups
     * @throws InvalidName
     * @throws InvalidSubject
     */
    public function __construct(
        public ?string $name = null,
        public ?string $durableName = null,
        public ?string $description = null,
        public DeliverPolicy $deliverPolicy = DeliverPolicy::All,
        public ?string $deliverSubject = null,
        public ?string $deliverGroup = null,
        public ?int $startSeq = null,
        public ?\DateTimeImmutable $startTime = null,
        public AckPolicy $ackPolicy = AckPolicy::Explicit,
        public ?TimeSpan $ackWait = null,
        public ?int $maxDeliver = null,
        public ?array $backoff = null,
        public ?string $filterSubject = null,
        public ?bool $flowControl = null,
        public ?TimeSpan $idleHeartbeat = null,
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
    ) {
        foreach ([$this->name, $this->durableName] as $consumerName) {
            // Empty means no name: the consumer gets a generated one.
            if ($consumerName !== null && $consumerName !== '') {
                Validate::name($consumerName);
            }
        }

        if ($this->deliverSubject !== null) {
            Validate::publishSubject($this->deliverSubject);
        }

        if ($this->deliverGroup !== null) {
            Validate::queueGroup($this->deliverGroup);
        }

        if ($this->filterSubject !== null && $this->filterSubject !== '') {
            Validate::subscribeSubject($this->filterSubject);
        }

        foreach ($this->filterSubjects ?? [] as $subject) {
            Validate::subscribeSubject($subject);
        }

        foreach ($this->priorityGroups ?? [] as $group) {
            Validate::priorityGroup($group);
        }
    }

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
                'deliver_subject' => $this->deliverSubject,
                'deliver_group' => $this->deliverGroup,
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
                'flow_control' => $this->flowControl,
                'idle_heartbeat' => $this->idleHeartbeat?->toNanoseconds(),
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
