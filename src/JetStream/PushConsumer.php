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
    public function consume(callable $handler, ?Cancellation $cancellation = null): Subscription
    {
        if ($this->subscription !== null) {
            throw new ConsumerAlreadyConsuming();
        }

        $acks = $this->acks;

        $subscription = $this->nats->subscribe(
            subject: $this->deliverSubject,
            handler: static function (NatsDelivery $delivery, Subscription $subscription) use (
                $handler,
                $acks,
            ): void {
                $status = $delivery->message->headers?->statusCode();

                if (($status ?? Status::OK) !== Status::OK) {
                    $description = $delivery->message->headers?->statusDescription();

                    if ($status === Status::Control && $description?->value === Description::FlowControl) {
                        $delivery->reply(new Message());
                    } elseif ($status === Status::Conflict && $description?->value === Description::ConsumerDeleted) {
                        $subscription->stop();
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

        return $this->subscription = $subscription;
    }

    public function stop(?Cancellation $cancellation = null): void
    {
        try {
            $this->subscription?->stop($cancellation);
        } finally {
            $this->subscription = null;
        }
    }

    public function drain(?Cancellation $cancellation = null): void
    {
        try {
            $this->subscription?->drain($cancellation);
        } finally {
            $this->subscription = null;
        }
    }
}
