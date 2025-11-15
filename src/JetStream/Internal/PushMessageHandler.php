<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Internal;

use Thesis\Nats\Client;
use Thesis\Nats\Delivery as NatsDelivery;
use Thesis\Nats\Description;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\Message;
use Thesis\Nats\Status;
use Thesis\Nats\Subscription;

/**
 * @internal
 */
final readonly class PushMessageHandler
{
    /**
     * @param callable(JetStreamDelivery, Subscription): void $handler
     */
    public function __construct(
        private mixed $handler,
        private Client $nc,
        private Heartbeat\Watchdog $watchdog,
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
                case [Status::Control, Description::FlowControl]:
                    $delivery->reply(new Message());
                    break;
                case [Status::Conflict, Description::ConsumerDeleted]:
                    $subscription->stop();
                    break;
            }

            return;
        }

        if ($delivery->replyTo !== null) {
            ($this->handler)($this->nc->toJetStreamDelivery($delivery), $subscription);
        }
    }
}
