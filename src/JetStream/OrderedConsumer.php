<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Amp\Cancellation;
use Amp\Pipeline;
use Thesis\Nats\Client;
use Thesis\Nats\Exception\ConsumerAlreadyConsuming;
use Thesis\Nats\Internal\Iter;
use Thesis\Nats\Iterator;
use Thesis\Nats\JetStream;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\JetStream\Internal\Ordered;
use Thesis\Nats\NatsException;
use Thesis\Nats\Subscription;
use function Amp\async;
use function Thesis\Nats\Internal\Id\generateUniqueId;

/**
 * Reads a stream in order without gaps and without acks, e.g. to replay it: the server consumers behind it are created,
 * replaced and deleted by the client. A new consume() continues after the last message delivered.
 *
 * @api
 */
final class OrderedConsumer
{
    private ?Subscription $subscription = null;

    private readonly Ordered\Cursor $cursor;

    /** @var non-empty-string */
    private readonly string $namePrefix;

    /**
     * @param non-empty-string $stream
     */
    public function __construct(
        public readonly string $stream,
        public readonly OrderedConsumerConfig $config,
        private readonly JetStream $js,
        private readonly Client $nats,
    ) {
        $this->cursor = new Ordered\Cursor();
        $this->namePrefix = $config->namePrefix ?? generateUniqueId(10);
    }

    /**
     * @param callable(JetStreamDelivery, Subscription): void $handler
     * @throws ConsumerAlreadyConsuming
     * @throws NatsException
     */
    public function consume(callable $handler, ?Cancellation $cancellation = null): Subscription
    {
        if ($this->subscription !== null && !$this->subscription->completed()) {
            throw new ConsumerAlreadyConsuming();
        }

        $inbox = $this->nats->newInbox();

        $messageHandler = new Ordered\OrderedMessageHandler(
            handler: $handler,
            nc: $this->nats,
            js: $this->js,
            config: $this->config,
            cursor: $this->cursor,
            stream: $this->stream,
            namePrefix: $this->namePrefix,
            inbox: $inbox,
        );

        // Nothing is delivered to the inbox itself: each server consumer gets its own subject attached to this subscription.
        $subscription = $this->nats->subscribe(
            subject: $inbox,
            handler: $messageHandler,
            cancellation: $cancellation,
            pendingLimits: $this->config->pendingLimits,
        );

        try {
            $messageHandler->start($subscription, $cancellation);
        } catch (\Throwable $e) {
            // The consumer may have been created even so, e.g. when the cancellation came after the request was sent.
            $messageHandler->stop();
            $subscription->stop();

            throw $e;
        }

        $unwatch = $this->nats->watchConnection($messageHandler->disconnected(...), $messageHandler->reconnected(...));

        // Not onComplete(): it is not called when the subscription is completed from the outside, e.g. by the client.
        $future = async(static function () use (
            $subscription,
            $messageHandler,
            $unwatch,
        ): void {
            try {
                $subscription->awaitCompletion();
            } catch (\Throwable) {
            } finally {
                $unwatch();
                $messageHandler->stop();
            }
        });
        $future->ignore();

        return $this->subscription = $subscription;
    }

    /**
     * The same as {@see self::consume()}, but the messages are read from the iterator.
     *
     * @return Iterator<JetStreamDelivery>
     * @throws ConsumerAlreadyConsuming
     * @throws NatsException
     */
    public function consumeIterator(?Cancellation $cancellation = null): Iterator
    {
        /** @var Pipeline\Queue<JetStreamDelivery> $queue */
        $queue = new Pipeline\Queue();

        $subscription = $this->consume(
            static function (JetStreamDelivery $delivery) use ($queue): void {
                Iter\push($queue, $delivery);
            },
            $cancellation,
        );

        return Iter\PipelineIterator::fromSubscription($queue, $subscription);
    }

    public function stop(?Cancellation $cancellation = null): void
    {
        $this->subscription?->stop($cancellation);
    }

    public function drain(?Cancellation $cancellation = null): void
    {
        $this->subscription?->drain($cancellation);
    }
}
