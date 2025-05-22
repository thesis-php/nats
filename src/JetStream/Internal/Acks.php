<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Internal;

use Amp\Cancellation;
use Thesis\Nats\Client;
use Thesis\Nats\Message;
use Thesis\Nats\NatsException;
use Thesis\Time\TimeSpan;

/**
 * @internal
 */
final readonly class Acks
{
    public function __construct(
        private Client $client,
    ) {}

    /**
     * @param non-empty-string $replyTo
     * @throws NatsException
     */
    public function ack(string $replyTo, bool $sync = false, ?Cancellation $cancellation = null): void
    {
        $handler = match ($sync) {
            true => $this->client->request(...),
            default => $this->client->publish(...),
        };

        $handler($replyTo, new Message('+ACK'), cancellation: $cancellation);
    }

    /**
     * @param non-empty-string $replyTo
     * @throws NatsException
     */
    public function nack(string $replyTo, ?TimeSpan $delay = null, ?Cancellation $cancellation = null): void
    {
        $body = '-NAK';
        if ($delay !== null) {
            $body .= \sprintf(' {"delay": %d}', $delay->toNanoseconds());
        }

        $this->client->publish($replyTo, new Message($body), cancellation: $cancellation);
    }

    /**
     * @param non-empty-string $replyTo
     * @throws NatsException
     */
    public function inProgress(string $replyTo, ?Cancellation $cancellation = null): void
    {
        $this->client->publish($replyTo, new Message('+WPI'), cancellation: $cancellation);
    }

    /**
     * @param non-empty-string $replyTo
     * @param ?non-empty-string $reason
     * @throws NatsException
     */
    public function terminate(string $replyTo, ?string $reason = null, ?Cancellation $cancellation = null): void
    {
        $body = '+TERM';
        if ($reason !== null) {
            $body .= " {$reason}";
        }

        $this->client->publish($replyTo, new Message($body), cancellation: $cancellation);
    }
}
