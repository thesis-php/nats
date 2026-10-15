<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Subscription;

use Thesis\Nats\Delivery;
use Thesis\Nats\PendingLimits;
use Thesis\Nats\Subscription;
use Thesis\Nats\SubscriptionStats;

/**
 * The messages a subscription has received, but not yet passed to its handler, shared by the handler, which accounts
 * them, and the public {@see Subscription}, which exposes the stats. See {@see PendingLimits} for how the limits differ
 * from the buffer size of an iterator. The limits are the only thing that bounds the memory the pending messages take:
 * the connection never waits for a slow handler, so that it keeps answering the server and serving the other subscriptions.
 *
 * @internal
 */
final class Backlog
{
    /** @var non-negative-int */
    private int $messages = 0;

    /** @var non-negative-int */
    private int $bytes = 0;

    /** @var non-negative-int */
    private int $delivered = 0;

    /** @var non-negative-int */
    private int $dropped = 0;

    /** Whether the messages are being dropped: the overflow is reported once, until the handler catches up. */
    private bool $overflowed = false;

    /**
     * @param \Closure(): void $onOverflow
     */
    public function __construct(
        private readonly PendingLimits $limits,
        private readonly \Closure $onOverflow,
    ) {}

    /**
     * @return bool false if the message is dropped
     */
    public function admit(Delivery $delivery): bool
    {
        $size = self::size($delivery);

        if (($this->limits->messages !== null && $this->messages >= $this->limits->messages)
            || ($this->limits->bytes !== null && $this->bytes + $size > $this->limits->bytes)) {
            ++$this->dropped;

            if (!$this->overflowed) {
                $this->overflowed = true;
                ($this->onOverflow)();
            }

            return false;
        }

        $this->overflowed = false;
        ++$this->messages;
        $this->bytes += $size;

        return true;
    }

    /**
     * Forgets an admitted message that has not reached the handler, e.g. because the subscription has completed.
     */
    public function revoke(Delivery $delivery): void
    {
        $this->messages = max(0, $this->messages - 1);
        $this->bytes = max(0, $this->bytes - self::size($delivery));
    }

    public function deliver(Delivery $delivery): void
    {
        $this->revoke($delivery);
        ++$this->delivered;
    }

    public function clear(): void
    {
        $this->messages = 0;
        $this->bytes = 0;
    }

    public function stats(): SubscriptionStats
    {
        return new SubscriptionStats(
            pendingMessages: $this->messages,
            pendingBytes: $this->bytes,
            delivered: $this->delivered,
            dropped: $this->dropped,
        );
    }

    /**
     * Only the payload is counted, as in nats.go: the limit is about the memory the application data takes,
     * unlike {@see Delivery::size()}, which also counts the subject, the reply-to and the headers.
     *
     * @return non-negative-int
     */
    private static function size(Delivery $delivery): int
    {
        return \strlen($delivery->message->payload ?? '');
    }
}
