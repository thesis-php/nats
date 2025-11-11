<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Amp\Cancellation;
use Thesis\Nats\Client;
use Thesis\Nats\Delivery as NatsDelivery;
use Thesis\Nats\Description;
use Thesis\Nats\Exception\ConsumerAlreadyConsuming;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\JetStream\Internal\Acks;
use Thesis\Nats\JetStream\Internal\Heartbeat;
use Thesis\Nats\Message;
use Thesis\Nats\NatsException;
use Thesis\Nats\Status;
use Thesis\Nats\Subscription;

/**
 * @api
 */
final class PushConsumer
{
    private readonly Acks $acks;

    private ?Subscription $subscription = null;

    private ?Heartbeat\Timer $timer = null;

    /**
     * @param non-empty-string $deliverSubject
     */
    public function __construct(
        public readonly Api\ConsumerInfo $info,
        private readonly Client $nats,
        private readonly string $deliverSubject,
    ) {
        $this->acks = Acks::fromClient($nats);
    }

    /**
     * @param callable(JetStreamDelivery, Subscription): void $handler
     * @throws NatsException
     */
    public function consume(
        callable $handler,
        PushConsumeConfig $config = new PushConsumeConfig(),
        ?Cancellation $cancellation = null,
    ): Subscription {
        if ($this->subscription !== null && !$this->subscription->completed()) {
            throw new ConsumerAlreadyConsuming();
        }

        $acks = $this->acks;
        $timer = &$this->timer;

        $subscription = $this->nats->subscribe(
            subject: $this->deliverSubject,
            handler: static function (NatsDelivery $delivery, Subscription $subscription) use (
                $handler,
                $acks,
                &$timer,
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
                        case [Status::Control, Description::IdleHeartbeat]:
                            $timer?->reset();
                            break;
                    }

                    return;
                }

                if (($replyTo = $delivery->replyTo) !== null) {
                    $jsDelivery = new JetStreamDelivery(
                        message: $delivery->message,
                        subject: $delivery->subject,
                        acks: $acks,
                        metadata: Metadata::parse($replyTo),
                        replyTo: $replyTo,
                    );

                    $handler($jsDelivery, $subscription);
                }
            },
            queueGroup: $this->info->config->deliverGroup,
            cancellation: $cancellation,
        );

        if ($this->info->config->idleHeartbeat?->isPositive()) {
            $this->timer = new Heartbeat\Timer(
                $this->info->config->idleHeartbeat->mul(2),
                $subscription,
                $config->maxMissedHeartbeats,
            );
        }

        return $this->subscription = $subscription;
    }

    public function stop(?Cancellation $cancellation = null): void
    {
        $this->complete(static fn(Subscription $subscription) => $subscription->stop($cancellation));
    }

    public function drain(?Cancellation $cancellation = null): void
    {
        $this->complete(static fn(Subscription $subscription) => $subscription->drain($cancellation));
    }

    /**
     * @param \Closure(Subscription): void $do
     */
    private function complete(\Closure $do): void
    {
        try {
            if ($this->subscription !== null) {
                $do($this->subscription);
            }
        } finally {
            $this->timer = null;
            $this->subscription = null;
        }
    }
}
