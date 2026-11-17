<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Thesis\Nats\Exception\InvalidName;
use Thesis\Nats\Exception\InvalidSubject;
use Thesis\Nats\Internal\Validation\Validate;
use Thesis\Nats\JetStream\Api\DeliverPolicy;
use Thesis\Nats\JetStream\Api\ReplayPolicy;
use Thesis\Nats\PendingLimits;
use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class OrderedConsumerConfig
{
    private const int DEFAULT_HEARTBEAT_SECONDS = 5;
    private const int DEFAULT_INACTIVE_THRESHOLD_MINUTES = 5;

    public TimeSpan $heartbeat;

    public TimeSpan $inactiveThreshold;

    /**
     * @param list<non-empty-string> $filterSubjects the subjects to read, all of the stream if empty
     * @param DeliverPolicy $deliverPolicy where to start; once a message is delivered, a recreated consumer starts right after it
     * @param ?positive-int $startSeq the first stream sequence for {@see DeliverPolicy::ByStartSequence}
     * @param ?\DateTimeImmutable $startTime the first message time for {@see DeliverPolicy::ByStartTime}
     * @param ?TimeSpan $heartbeat how often the server reports itself when it has nothing to deliver; a consumer missing
     *                             two heartbeats in a row is recreated, 5 seconds by default
     * @param ?TimeSpan $inactiveThreshold how long the server keeps a consumer nobody reads from, 5 minutes by default
     * @param ?positive-int $maxResetAttempts how many times in a row the consumer is tried to be recreated before the subscription
     *                                        fails, null to keep trying
     * @param ?array<string, string> $metadata
     * @param ?non-empty-string $namePrefix the prefix of the consumer names, a random one by default
     * @param PendingLimits $pendingLimits the messages the handler has not taken yet; the ones beyond them are dropped,
     *                                     and the consumer is recreated from the first dropped one
     * @throws InvalidName
     * @throws InvalidSubject
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public array $filterSubjects = [],
        public DeliverPolicy $deliverPolicy = DeliverPolicy::All,
        public ?int $startSeq = null,
        public ?\DateTimeImmutable $startTime = null,
        public ReplayPolicy $replayPolicy = ReplayPolicy::Instant,
        public bool $headersOnly = false,
        ?TimeSpan $heartbeat = null,
        ?TimeSpan $inactiveThreshold = null,
        public ?int $maxResetAttempts = null,
        public ?array $metadata = null,
        public ?string $namePrefix = null,
        public PendingLimits $pendingLimits = new PendingLimits(),
    ) {
        foreach ($this->filterSubjects as $subject) {
            Validate::subscribeSubject($subject);
        }

        if ($this->namePrefix !== null) {
            Validate::name($this->namePrefix);
        }

        if ($this->startSeq !== null) {
            Validate::count($this->startSeq, 'start sequence');
        }

        if ($this->maxResetAttempts !== null) {
            Validate::count($this->maxResetAttempts, 'number of reset attempts');
        }

        $this->heartbeat = $heartbeat ?? TimeSpan::fromSeconds(self::DEFAULT_HEARTBEAT_SECONDS);
        $this->inactiveThreshold = $inactiveThreshold ?? TimeSpan::fromMinutes(self::DEFAULT_INACTIVE_THRESHOLD_MINUTES);

        Validate::duration($this->heartbeat->toSeconds(3), 'heartbeat');
        Validate::duration($this->inactiveThreshold->toSeconds(3), 'inactive threshold');
    }
}
