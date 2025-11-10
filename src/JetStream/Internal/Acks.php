<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Internal;

use Amp\Cancellation;
use Thesis\Nats\Delivery;
use Thesis\Nats\Message;
use Thesis\Time\TimeSpan;

/**
 * @internal
 */
final readonly class Acks
{
    /**
     * @param \Closure(non-empty-string, Message, ?Cancellation=): void $publish
     * @param \Closure(non-empty-string, Message, ?Cancellation=): Delivery $request
     */
    public function __construct(
        private \Closure $publish,
        private \Closure $request,
    ) {}

    /**
     * @param non-empty-string $replyTo
     */
    public function ack(string $replyTo, bool $sync = false, ?Cancellation $cancellation = null): void
    {
        $handler = match ($sync) {
            true => $this->request,
            default => $this->publish,
        };

        $handler(
            $replyTo,
            new Message('+ACK'),
            $cancellation,
        );
    }

    /**
     * @param non-empty-string $replyTo
     */
    public function nack(string $replyTo, ?TimeSpan $delay = null, ?Cancellation $cancellation = null): void
    {
        ($this->publish)(
            $replyTo,
            new Message('-NAK' . ($delay !== null ? \sprintf(' {"delay": %d}', $delay->toNanoseconds()) : '')),
            $cancellation,
        );
    }

    /**
     * @param non-empty-string $replyTo
     */
    public function inProgress(string $replyTo, ?Cancellation $cancellation = null): void
    {
        ($this->publish)(
            $replyTo,
            new Message('+WPI'),
            $cancellation,
        );
    }

    /**
     * @param non-empty-string $replyTo
     * @param ?non-empty-string $reason
     */
    public function terminate(string $replyTo, ?string $reason = null, ?Cancellation $cancellation = null): void
    {
        ($this->publish)(
            $replyTo,
            new Message('+TERM' . ($reason !== null ? " {$reason}" : '')),
            $cancellation,
        );
    }
}
