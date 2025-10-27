<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Amp\Cancellation;
use Amp\Pipeline;
use Thesis\Nats\Client;
use Thesis\Nats\Internal\QueueIterator;
use Thesis\Nats\Iterator;
use Thesis\Nats\NatsException;

/**
 * @api
 */
final class PushConsumer
{
    private bool $consuming = false;

    /**
     * @param non-empty-string $name
     * @param non-empty-string $stream
     */
    public function __construct(
        public readonly Api\ConsumerInfo $info,
        public readonly string $name,
        public readonly string $stream,
        private readonly Client $nats,
    ) {}

    /**
     * @return Iterator<Delivery>
     * @throws NatsException
     * @throws \LogicException if consumer is already running or deliver subject is not set
     */
    public function consume(?Cancellation $cancellation = null): Iterator
    {
        if ($this->consuming) {
            throw new \LogicException('Consumer is already running.');
        }

        /** @var Pipeline\Queue<Delivery> $queue */
        $queue = new Pipeline\Queue(bufferSize: $this->info->config->maxAckPending ?? 0);

        $messageHandler = new Internal\PushMessageHandler(
            nc: $this->nats,
            queue: $queue,
        );

        $sid = $this->nats->subscribe(
            subject: $this->info->config->deliverSubject ?? throw new \LogicException('Deliver subject must not be null.'),
            handler: $messageHandler,
            queueGroup: $this->info->config->deliverGroup,
            cancellation: $cancellation,
        );

        $this->consuming = true;

        return new QueueIterator(
            iterator: $queue->iterate(),
            queue: $queue,
            unsubscribe: function (?Cancellation $cancellation = null) use ($sid): void {
                $this->nats->unsubscribe($sid, $cancellation);
                $this->consuming = false;
            },
        );
    }
}
