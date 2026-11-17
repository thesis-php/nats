<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Amp\Cancellation;
use Amp\Future;
use Thesis\Nats\Header;
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
     * The size of the message payload. A consumer with headersOnly delivers no payload, then it is the size of the stored one.
     *
     * @return non-negative-int
     */
    public function payloadSize(): int
    {
        return $this->message->headers?->get(Header\MsgSize::header()) ?? \strlen($this->message->payload ?? '');
    }

    /**
     * Sends the acknowledgement without waiting for the server, see {@see self::ackSync()} to wait.
     *
     * @throws NatsException
     */
    public function ack(?Cancellation $cancellation = null): void
    {
        if (($reply = $this->replyTo) !== null) {
            $ack = $this->acks->ack(...);

            $this->doAck(static fn() => $ack($reply, $cancellation), $cancellation);
        }
    }

    /**
     * Acknowledges and waits until the server confirms it has processed the acknowledgement, so the message
     * is not redelivered afterwards. Throws when the server does not confirm it, e.g. the consumer is deleted.
     *
     * @throws NatsException
     */
    public function ackSync(?Cancellation $cancellation = null): void
    {
        if (($reply = $this->replyTo) !== null) {
            $ackSync = $this->acks->ackSync(...);

            $this->doAck(static fn() => $ackSync($reply, $cancellation), $cancellation);
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
