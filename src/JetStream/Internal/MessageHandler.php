<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Internal;

use Amp\Pipeline;
use Thesis\Nats\Client;
use Thesis\Nats\Delivery as NatsDelivery;
use Thesis\Nats\Exception\NoServerResponse;
use Thesis\Nats\JetStream\ConsumeConfig;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\JetStream\Metadata;
use Thesis\Nats\Json\Encoder;
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
     * @param Pipeline\Queue<JetStreamDelivery> $queue
     * @param non-empty-string $subject
     * @param non-empty-string $replyTo
     */
    public function __construct(
        private Pipeline\Queue $queue,
        Client $client,
        Encoder $json,
        ConsumeConfig $config,
        string $subject,
        string $replyTo,
    ) {
        $this->acks = new Acks($client);
        $this->heartbeats = new Heartbeat\Monitor(
            interval: $config->heartbeat ?? TimeSpan::fromSeconds(-1),
        );
        $this->pulls = new PullSupervisor(
            client: $client,
            json: $json,
            config: $config,
            subject: $subject,
            replyTo: $replyTo,
        );

        if ($config->heartbeat?->toSeconds() > 0) {
            $this->heartbeats->monitor(function (): void {
                $this->queue->error(new NoServerResponse());
            });
        }
    }

    public function __invoke(NatsDelivery $delivery): void
    {
        $replyTo = $delivery->replyTo;

        if ($replyTo === null && $delivery->message->payload === null) {
            $this->heartbeats->reset();
        }

        if ($replyTo !== null) {
            $this->queue->push(
                new JetStreamDelivery(
                    message: $delivery->message,
                    subject: $delivery->subject,
                    metadata: Metadata::parse($replyTo),
                    replyTo: $replyTo,
                    acks: $this->acks,
                ),
            );

            $this->pulls->request();
        }
    }

    public function stop(): void
    {
        $this->pulls->stop();
        $this->heartbeats->stop();
    }
}
