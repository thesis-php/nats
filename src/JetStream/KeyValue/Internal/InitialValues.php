<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\KeyValue\Internal;

use Amp\DeferredFuture;
use Amp\Future;

/**
 * Tracks the values a watch has to deliver before it is up to date: as many as the consumer had pending when it was created.
 * Like nats.go, the count is cut short by a message with nothing pending after it, e.g. when some of the values were removed meanwhile,
 * and unlike nats.go, by an idle heartbeat, when the values were removed before any was delivered.
 *
 * @internal
 */
final class InitialValues
{
    /** @var DeferredFuture<void> */
    private readonly DeferredFuture $delivered;

    private int $received = 0;

    /**
     * @param non-negative-int $pending
     */
    public function __construct(
        private readonly int $pending,
    ) {
        /** @var DeferredFuture<void> $delivered */
        $delivered = new DeferredFuture();
        $this->delivered = $delivered;

        if ($this->pending === 0) {
            $this->delivered->complete();
        }
    }

    public static function none(): self
    {
        return new self(0);
    }

    /**
     * @param non-negative-int $pendingAfter the number of messages the consumer has pending after the received one
     */
    public function receive(int $pendingAfter): void
    {
        if ($this->delivered()) {
            return;
        }

        if (++$this->received >= $this->pending || $pendingAfter === 0) {
            $this->delivered->complete();
        }
    }

    public function complete(): void
    {
        if (!$this->delivered()) {
            $this->delivered->complete();
        }
    }

    public function delivered(): bool
    {
        return $this->delivered->isComplete();
    }

    /**
     * @return Future<void>
     */
    public function future(): Future
    {
        return $this->delivered->getFuture();
    }
}
