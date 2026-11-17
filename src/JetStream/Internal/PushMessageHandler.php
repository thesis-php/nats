<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Internal;

use Thesis\Nats\Client;
use Thesis\Nats\Delivery as NatsDelivery;
use Thesis\Nats\Description;
use Thesis\Nats\Exception\ConsumerSequenceMismatch;
use Thesis\Nats\Header;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\JetStream\Metadata;
use Thesis\Nats\Message;
use Thesis\Nats\Status;
use Thesis\Nats\Subscription;

/**
 * @internal
 */
final class PushMessageHandler
{
    /** The last message received, null until the first one: the heartbeats are compared with it. */
    private ?Metadata $last = null;

    /** The consumer sequence of the last gap reported. */
    private int $reported = 0;

    /**
     * @param callable(JetStreamDelivery, Subscription): void $handler
     * @param bool $sequenced whether this subscription receives every message of the consumer, i.e. it has no deliver group,
     *                        and so a heartbeat naming a later consumer sequence than the last one received means a gap
     */
    public function __construct(
        private readonly mixed $handler,
        private readonly Client $nc,
        private readonly Heartbeat\Watchdog $watchdog,
        private readonly bool $sequenced = false,
    ) {}

    public function __invoke(NatsDelivery $delivery, Subscription $subscription): void
    {
        // When we begin processing a message, we disable the heartbeat watchdog to prevent the subscription from being canceled
        // if the processing time exceeds the heartbeat interval. This is necessary because during this time
        // we cannot signal to the watchdog that heartbeats are being received.
        $this->watchdog->stop();

        $this->doHandleDelivery($delivery, $subscription);

        // If the subscription was not completed during message processing,
        // we must resume the watchdog in any case, even if the received message wasn't a heartbeat,
        // because any message from the NATS server proves its availability.
        if (!$subscription->completed()) {
            $this->watchdog->reset();
        }
    }

    private function doHandleDelivery(
        NatsDelivery $delivery,
        Subscription $subscription,
    ): void {
        $status = $delivery->message->headers?->statusCode();

        if (($status ?? Status::OK) !== Status::OK) {
            $description = $delivery->message->headers?->statusDescription();

            switch ([$status, $description]) {
                case [Status::Control, Description::FlowControl]: // @phpstan-ignore switch.type
                    $delivery->reply(new Message());
                    break;
                case [Status::Control, Description::IdleHeartbeat]: // @phpstan-ignore switch.type
                    $stalled = $delivery->message->headers?->exists(Header\ConsumerStalled::header()) ?? false;

                    $this->detectGap($delivery);

                    if (!$stalled && $this->handler instanceof IdleListener) {
                        $this->handler->idle();
                    }
                    break;
                case [Status::Conflict, Description::ConsumerDeleted]: // @phpstan-ignore switch.type
                    $subscription->stop();
                    break;
            }

            return;
        }

        if ($delivery->replyTo !== null) {
            $jsDelivery = $this->nc->toJetStreamDelivery($delivery);
            $this->last = $jsDelivery->metadata;

            ($this->handler)($jsDelivery, $subscription);
        }
    }

    /**
     * Like nats.go, compares the consumer sequence of the heartbeat with the last message received, but reports a gap once.
     */
    private function detectGap(NatsDelivery $heartbeat): void
    {
        $last = $this->last;
        $delivered = $heartbeat->message->headers?->get(Header\LastConsumer::header());

        if (!$this->sequenced || $last === null || $delivered === null || $delivered <= max($last->consumerSequence, $this->reported)) {
            return;
        }

        /** @var positive-int $streamResumeSequence */
        $streamResumeSequence = max($last->streamSequence, 0) + 1;

        $this->nc->reportError(new ConsumerSequenceMismatch(
            stream: $last->stream,
            consumer: $last->consumer,
            consumerSequence: max($last->consumerSequence, 0),
            lastConsumerSequence: $delivered,
            streamResumeSequence: $streamResumeSequence,
        ));

        // The next heartbeats name the same sequence until a message comes: the gap is already reported.
        $this->reported = $delivered;
    }
}
