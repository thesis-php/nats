<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Amp\Cancellation;
use Amp\Future;
use Thesis\Nats\JetStream\Internal\Acks;
use Thesis\Nats\Message;
use Thesis\Nats\NatsException;
use Thesis\Time\TimeSpan;
use function Amp\async;

/**
 * @api
 */
final class Delivery
{
    /** @var ?Future<void> */
    private ?Future $acked = null;

    /** @var ?Future<void> */
    private ?Future $wip = null;

    /**
     * @param non-empty-string $subject
     * @param ?non-empty-string $replyTo
     */
    public function __construct(
        public readonly Message $message,
        public readonly string $subject,
        private readonly Acks $acks,
        public readonly ?Metadata $metadata = null,
        private readonly ?string $replyTo = null,
    ) {}

    /**
     * @throws NatsException
     */
    public function ack(bool $sync = false, ?Cancellation $cancellation = null): void
    {
        if (($reply = $this->replyTo) !== null) {
            $ack = $this->acks->ack(...);

            $this->doAck(static fn() => $ack($reply, $sync, $cancellation), $cancellation);
        }
    }

    /**
     * @throws NatsException
     */
    public function nack(?TimeSpan $delay = null, ?Cancellation $cancellation = null): void
    {
        if (($reply = $this->replyTo) !== null) {
            $nack = $this->acks->nack(...);

            $this->doAck(static fn() => $nack($reply, $delay, $cancellation), $cancellation);
        }
    }

    /**
     * @param ?non-empty-string $reason
     * @throws NatsException
     */
    public function terminate(?string $reason = null, ?Cancellation $cancellation = null): void
    {
        if (($reply = $this->replyTo) !== null) {
            $terminate = $this->acks->terminate(...);

            $this->doAck(static fn() => $terminate($reply, $reason, $cancellation), $cancellation);
        }
    }

    /**
     * @throws NatsException
     */
    public function inProgress(?Cancellation $cancellation = null): void
    {
        $reply = $this->replyTo;
        if ($reply === null) {
            return;
        }

        if ($this->acked !== null) {
            return;
        }

        $this->wip ??= async($this->acks->inProgress(...), $reply, $cancellation);

        try {
            $this->wip->await($cancellation);
        } finally {
            $this->wip = null;
        }
    }

    /**
     * @param \Closure(): void $handler
     */
    private function doAck(\Closure $handler, ?Cancellation $cancellation = null): void
    {
        while ($this->wip !== null) {
            $this->wip->await($cancellation);
        }

        ($this->acked ??= async($handler))->await($cancellation);
    }
}
