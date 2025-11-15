<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Internal;

use Amp\Pipeline;
use Revolt\EventLoop;
use Thesis\Nats\Client;
use Thesis\Nats\Delivery as NatsDelivery;
use Thesis\Nats\Description;
use Thesis\Nats\Exception\BadRequestSent;
use Thesis\Nats\Exception\ConsumerDeleted;
use Thesis\Nats\Header;
use Thesis\Nats\JetStream\Api\PullRequest;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\JetStream\PullConsumeConfig;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\Message;
use Thesis\Nats\Status;
use Thesis\Nats\Subscription;

/**
 * @internal
 */
final class PullMessageHandler
{
    private int $pendingMessages;

    private ?int $pendingBytes;

    /** @var non-negative-int */
    private int $delivered = 0;

    /** @var ?non-empty-string */
    private ?string $pinId = null;

    private readonly Heartbeat\Watchdog $watchdog;

    /** @var Pipeline\Queue<PullRequest> */
    private readonly Pipeline\Queue $pulls;

    /**
     * @param callable(JetStreamDelivery, Subscription): void $handler
     * @param non-empty-string $subject
     * @param non-empty-string $reply
     */
    public function __construct(
        private readonly mixed $handler,
        private readonly Client $nc,
        private readonly PullConsumeConfig $config,
        Encoder $json,
        string $subject,
        string $reply,
    ) {
        $this->pendingMessages = $config->maxMessages;
        $this->pendingBytes = $config->maxBytes;
        $pinId = &$this->pinId;

        /** @var Pipeline\Queue<PullRequest> $queue */
        $queue = new Pipeline\Queue();
        $this->pulls = $queue;

        $this->watchdog = new Heartbeat\Watchdog($this->config->heartbeat->mul(2));
        $this->watchdog->subscribe(static function () use (
            $queue,
            $config,
            &$pinId,
        ): void {
            $queue->push(new PullRequest(
                expires: $config->expires,
                batch: $config->maxMessages,
                maxBytes: $config->maxBytes,
                noWait: $config->noWait,
                heartbeat: $config->heartbeat,
                minPending: $config->minPending,
                minAckPending: $config->minAckPending,
                pinId: $pinId,
                group: $config->group,
            ));
        });

        EventLoop::queue(static function () use (
            $queue,
            $subject,
            $reply,
            $json,
            $nc,
        ): void {
            foreach ($queue->iterate() as $pull) {
                $nc->publish(
                    subject: $subject,
                    message: new Message($json->encode($pull)),
                    replyTo: $reply,
                );
            }
        });
    }

    public function __invoke(NatsDelivery $delivery, Subscription $subscription): void
    {
        $this->watchdog->stop();

        $this->doHandle($delivery, $subscription);

        if (!$subscription->completed()) {
            $this->fetch();
        }

        $this->watchdog->reset();
    }

    public function stop(): void
    {
        $this->watchdog->stop();

        if (!$this->pulls->isComplete()) {
            $this->pulls->complete();
        }
    }

    private function doHandle(NatsDelivery $delivery, Subscription $subscription): void
    {
        if ($delivery->message->headers !== null && !$delivery->message->headers->ok()) {
            $this->digest($delivery, $subscription);

            return;
        }

        $this->deliver($delivery, $subscription);

        $this->pinId ??= $delivery->message->headers?->get(Header\PinId::header());
    }

    private function digest(NatsDelivery $delivery, Subscription $subscription): void
    {
        $statusCode = $delivery->message->headers?->statusCode();
        $statusDescription = $delivery->message->headers?->statusDescription();

        if ($statusCode === Status::BadRequest) {
            $subscription->error(new BadRequestSent($statusDescription->value ?? ''));
        } elseif ($statusCode === Status::PinIdMismatch) {
            $this->pinId = null;
            $this->reset();
        } elseif ($statusDescription?->is(Description::ConsumerDeleted)) {
            $subscription->error(new ConsumerDeleted());
        } elseif ($statusDescription?->is(Description::LeadershipChange)) {
            $this->reset();
        } elseif ($statusDescription?->is(Description::MaxBytesExceeded, Description::BatchCompleted, Description::RequestTimeout)) {
            $messagesLeft = $delivery->message->headers?->get(Header\PendingMessages::header()) ?? 0;
            $bytesLeft = $delivery->message->headers?->get(Header\PendingBytes::header()) ?? 0;

            $this->pendingMessages -= $messagesLeft;

            if ($this->pendingBytes !== null) {
                $this->pendingBytes -= $bytesLeft;
            }
        }
    }

    private function deliver(NatsDelivery $delivery, Subscription $subscription): void
    {
        ($this->handler)($this->nc->toJetStreamDelivery($delivery), $subscription);

        --$this->pendingMessages;
        ++$this->delivered;

        if ($this->pendingBytes !== null) {
            $this->pendingBytes = max($this->pendingBytes - $delivery->size(), 0);
        }
    }

    private function fetch(): void
    {
        if ($this->pendingMessages > $this->config->messagesThreshold || ($this->pendingBytes !== null && $this->pendingBytes > $this->config->bytesThreshold)) {
            return;
        }

        $batch = $this->config->maxMessages - $this->pendingMessages;
        $maxBytes = $this->pendingBytes;

        if ($maxBytes !== null && $this->config->maxBytes !== null) {
            $maxBytes = $this->config->maxBytes - $this->pendingBytes;
            $batch = $this->config->maxMessages;
        }

        if ($batch > 0) {
            $this->pulls->push(new PullRequest(
                expires: $this->config->expires,
                batch: $batch,
                maxBytes: $maxBytes !== null ? max($maxBytes, 0) : null,
                noWait: $this->config->noWait,
                heartbeat: $this->config->heartbeat,
                minPending: $this->config->minPending,
                minAckPending: $this->config->minAckPending,
                pinId: $this->pinId,
                group: $this->config->group,
            ));

            $this->reset();
        }
    }

    private function reset(): void
    {
        $this->pendingMessages = $this->config->maxMessages;
        $this->pendingBytes = $this->config->maxBytes;
    }
}
