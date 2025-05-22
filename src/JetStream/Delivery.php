<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Amp\Cancellation;
use Amp\Future;
use Thesis\Nats\JetStream\Internal\Acks;
use Thesis\Nats\Message;
use Thesis\Nats\NatsException;
use Thesis\Sync;
use Thesis\Time\TimeSpan;
use function Amp\async;
use function Amp\weakClosure;

/**
 * @api
 */
final class Delivery
{
    /** @var ?Sync\Once<bool> */
    private ?Sync\Once $acked = null;

    /** @var ?Future<void> */
    private ?Future $wip = null;

    /**
     * @param non-empty-string $subject
     * @param non-empty-string $replyTo
     */
    public function __construct(
        public readonly Message $message,
        public readonly string $subject,
        private readonly string $replyTo,
        private readonly Acks $acks,
    ) {}

    /**
     * @throws NatsException
     */
    public function ack(bool $sync = false, ?Cancellation $cancellation = null): void
    {
        $this->doAck(
            handler: weakClosure(function () use ($sync, $cancellation): void {
                $this->acks->ack($this->replyTo, $sync, $cancellation);
            }),
            cancellation: $cancellation,
        );
    }

    /**
     * @throws NatsException
     */
    public function nack(?TimeSpan $delay = null, ?Cancellation $cancellation = null): void
    {
        $this->doAck(
            handler: weakClosure(function () use ($delay, $cancellation): void {
                $this->acks->nack($this->replyTo, $delay, $cancellation);
            }),
            cancellation: $cancellation,
        );
    }

    /**
     * @throws NatsException
     */
    public function inProgress(?Cancellation $cancellation = null): void
    {
        if ($this->acked?->await($cancellation)) {
            return;
        }

        $this->wip ??= async($this->acks->inProgress(...), $this->replyTo);

        try {
            $this->wip->await($cancellation);
        } finally {
            $this->wip = null;
        }
    }

    /**
     * @param ?non-empty-string $reason
     * @throws NatsException
     */
    public function terminate(?string $reason = null, ?Cancellation $cancellation = null): void
    {
        $this->doAck(
            handler: weakClosure(function () use ($reason, $cancellation): void {
                $this->acks->terminate($this->replyTo, $reason, $cancellation);
            }),
            cancellation: $cancellation,
        );
    }

    /**
     * @param callable(): void $handler
     * @throws NatsException
     */
    private function doAck(callable $handler, ?Cancellation $cancellation = null): void
    {
        while ($this->wip !== null) {
            $this->wip->await($cancellation);
        }

        $ack = static function () use ($handler): bool {
            $handler();

            return true;
        };

        ($this->acked ??= new Sync\Once($ack))->await($cancellation);
    }
}
