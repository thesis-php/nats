<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Internal;

use Thesis\Nats\Client;
use Thesis\Nats\Delivery as NatsDelivery;
use Thesis\Nats\Header\StatusCode;
use Thesis\Nats\JetStream\ConsumeConfig;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\JetStream\Metadata;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\Status;
use Thesis\Nats\Subscription;
use Thesis\Time\TimeSpan;

/**
 * @internal
 */
final readonly class MessageHandler
{
    private Acks $acks;

    private Heartbeat\Monitor $heartbeats;

    private PullSupervisor $pulls;

    /**
     * @param callable(JetStreamDelivery, Subscription): void $handler
     * @param non-empty-string $subject
     * @param non-empty-string $replyTo
     */
    public function __construct(
        private mixed $handler,
        Client $nats,
        Encoder $json,
        private ConsumeConfig $config,
        string $subject,
        string $replyTo,
    ) {
        $this->acks = new Acks($nats);
        $this->heartbeats = new Heartbeat\Monitor(
            interval: $config->heartbeat ?? TimeSpan::fromSeconds(-1),
        );
        $this->pulls = new PullSupervisor(
            nats: $nats,
            json: $json,
            config: $config,
            subject: $subject,
            replyTo: $replyTo,
        );

        if ($config->heartbeat?->toSeconds() > 0) {
            $this->heartbeats->monitor($this->pulls->next(...));
        }
    }

    public function __invoke(NatsDelivery $delivery, Subscription $subscription): void
    {
        if ($delivery->message->headers?->get(StatusCode::Header) === Status::NoMessages && $this->config->completeOnNoMessages) {
            $subscription->stop();
            $this->stop();

            return;
        }

        $replyTo = $delivery->replyTo;

        if ($replyTo === null && $delivery->message->payload === null) {
            $this->heartbeats->reset();
        }

        if ($replyTo !== null) {
            ($this->handler)(
                new JetStreamDelivery(
                    message: $delivery->message,
                    subject: $delivery->subject,
                    metadata: Metadata::parse($replyTo),
                    replyTo: $replyTo,
                    acks: $this->acks,
                ),
                $subscription,
            );

            $this->heartbeats->reset();
            $this->pulls->request();
        }
    }

    public function stop(): void
    {
        $this->pulls->stop();
        $this->heartbeats->stop();
    }
}
