<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Internal;

use Revolt\EventLoop;
use Thesis\Nats\Client;
use Thesis\Nats\Delivery as NatsDelivery;
use Thesis\Nats\JetStream\ConsumeConfig;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\JetStream\Metadata;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\Message;

/**
 * @internal
 */
final readonly class MessageHandler
{
    private Sync\Barrier $barrier;

    private Acks $acks;

    /**
     * @param callable(JetStreamDelivery): void $handler
     * @param non-empty-string $subject
     * @param non-empty-string $replyTo
     */
    public function __construct(
        private mixed $handler,
        Client $client,
        Encoder $json,
        ConsumeConfig $config,
        string $subject,
        string $replyTo,
    ) {
        $barrier = new Sync\Barrier($config->batch);
        $barrier->idle();

        $this->barrier = $barrier;
        $this->acks = new Acks($client);

        EventLoop::queue(static function () use (
            $barrier,
            $client,
            $json,
            $config,
            $subject,
            $replyTo,
        ): void {
            foreach ($barrier as $_) {
                $client->publish(
                    subject: $subject,
                    message: new Message($json->encode($config)),
                    replyTo: $replyTo,
                );
            }

            $barrier->close();
        });
    }

    public function __invoke(NatsDelivery $delivery): void
    {
        $replyTo = $delivery->replyTo;

        if ($replyTo !== null) {
            ($this->handler)(new JetStreamDelivery(
                message: $delivery->message,
                subject: $delivery->subject,
                metadata: Metadata::parse($replyTo),
                replyTo: $replyTo,
                acks: $this->acks,
            ));

            $this->barrier->arrive();
        }
    }

    public function stop(): void
    {
        $this->barrier->close();
    }
}
