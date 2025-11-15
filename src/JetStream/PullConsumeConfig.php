<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Thesis\Time\TimeSpan;

/**
 * @api
 */
final readonly class PullConsumeConfig
{
    private const int DEFAULT_PULL_EXPIRES_SECS = 30;
    private const int DEFAULT_MAX_MESSAGES = 500;
    private const int DEFAULT_BYTES_MAX_MESSAGES = 1_000_000;
    private const int MAX_HEARTBEAT_TIME_SECS = 30;

    public TimeSpan $expires;

    public TimeSpan $heartbeat;

    /** @var positive-int */
    public int $maxMessages;

    /** @var positive-int */
    public int $messagesThreshold;

    /** @var ?positive-int */
    public ?int $maxBytes;

    /** @var ?positive-int */
    public ?int $bytesThreshold;

    /**
     * @param ?positive-int $maxMessages
     * @param ?positive-int $maxBytes
     * @param ?non-empty-string $pinId
     * @param ?non-empty-string $group
     * @param ?non-negative-int $priority
     */
    public function __construct(
        ?TimeSpan $expires = null,
        ?int $maxMessages = null,
        ?int $maxBytes = null,
        ?TimeSpan $heartbeat = null,
        public ?int $minPending = null,
        public ?int $minAckPending = null,
        public ?string $pinId = null,
        public ?string $group = null,
        public ?int $priority = null,
    ) {
        $expires ??= TimeSpan::fromSeconds(self::DEFAULT_PULL_EXPIRES_SECS);

        if ($maxMessages !== null && $maxBytes !== null) {
            throw new \LogicException('Only one of the maxMessages or maxBytes parameters can be specified.');
        }

        $maxMessages ??= self::DEFAULT_MAX_MESSAGES;

        if ($maxBytes !== null) {
            $maxMessages = self::DEFAULT_BYTES_MAX_MESSAGES;
        }

        $this->maxBytes = $maxBytes;
        $this->maxMessages = $maxMessages;
        $this->messagesThreshold = max((int) ceil($maxMessages / 2), 1);
        $this->bytesThreshold = $maxBytes !== null ? max((int) ceil($maxBytes / 2), 1) : null;

        $heartbeat ??= $expires->div(2);

        if ($heartbeat->isGreaterThan(TimeSpan::fromSeconds(self::MAX_HEARTBEAT_TIME_SECS))) {
            $heartbeat = TimeSpan::fromSeconds(self::MAX_HEARTBEAT_TIME_SECS);
        }

        if ($heartbeat->isGreaterThan($expires->div(2))) {
            throw new \LogicException('Heartbeat value must be less than 50% of expires.');
        }

        $this->heartbeat = $heartbeat;
        $this->expires = $expires;
    }
}
