<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Amp\Cancellation;
use Amp\Pipeline;
use Thesis\Nats\Client;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\Internal\QueueIterator;
use Thesis\Nats\Iterator;
use Thesis\Nats\JetStream;
use Thesis\Nats\JetStream\Api\Router;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\NatsException;

/**
 * @api
 */
final class Consumer
{
    /** @var array<non-empty-string, Internal\MessageHandler> */
    private array $subscribers = [];

    /**
     * @param non-empty-string $name
     * @param non-empty-string $stream
     */
    public function __construct(
        public readonly Api\ConsumerInfo $info,
        public readonly string $name,
        public readonly string $stream,
        private readonly JetStream $js,
        private readonly Client $client,
        private readonly Router $router,
        private readonly Encoder $json,
    ) {}

    /**
     * @throws NatsException
     */
    public function actualInfo(): Api\ConsumerInfo
    {
        return $this->js->consumerInfo($this->stream, $this->name);
    }

    /**
     * @return Iterator<Delivery>
     * @throws NatsException
     */
    public function consume(
        ConsumeConfig $config = new ConsumeConfig(),
        ?Cancellation $cancellation = null,
    ): Iterator {
        $id = Id\generateInboxId();

        /** @var Pipeline\Queue<Delivery> $queue */
        $queue = new Pipeline\Queue(bufferSize: $config->batch);

        $messageHandler = new Internal\MessageHandler(
            queue: $queue,
            client: $this->client,
            json: $this->json,
            config: $config,
            subject: $this->router->route(Api\ApiMethod::ConsumerMessageNext->compile($this->stream, $this->name)),
            replyTo: $id,
        );

        $sid = $this->client->subscribe(
            subject: $id,
            handler: $messageHandler,
            cancellation: $cancellation,
        );

        $this->subscribers[$sid] = $messageHandler;

        return new QueueIterator(
            iterator: $queue->iterate(),
            complete: function (?Cancellation $cancellation = null) use ($sid, $queue): void {
                $this->unsubscribe($sid, $cancellation);
                $queue->complete();
            },
            cancel: function (\Throwable $e, ?Cancellation $cancellation = null) use ($sid, $queue): void {
                $this->unsubscribe($sid, $cancellation);
                $queue->error($e);
            },
        );
    }

    /**
     * @throws NatsException
     */
    public function delete(): Api\ConsumerDeleted
    {
        return $this->js->deleteConsumer(
            stream: $this->stream,
            consumer: $this->name,
        );
    }

    /**
     * @throws NatsException
     */
    public function pause(\DateTimeImmutable $pauseUntil): Api\ConsumerPaused
    {
        return $this->js->pauseConsumer(
            stream: $this->stream,
            consumer: $this->name,
            pauseUntil: $pauseUntil,
        );
    }

    /**
     * @throws NatsException
     */
    public function resume(): Api\ConsumerPaused
    {
        return $this->js->resumeConsumer(
            stream: $this->stream,
            consumer: $this->name,
        );
    }

    /**
     * @param non-empty-string $group
     * @throws NatsException
     */
    public function unpin(string $group): void
    {
        $this->js->unpinConsumer(
            stream: $this->stream,
            consumer: $this->name,
            group: $group,
        );
    }

    /**
     * @throws NatsException
     */
    public function unsubscribeAll(): void
    {
        foreach ($this->subscribers as $sid => $messageHandler) {
            $this->client->unsubscribe($sid);
            unset($this->subscribers[$sid]);

            $messageHandler->stop();
        }
    }

    /**
     * @param non-empty-string $sid
     * @throws NatsException
     */
    private function unsubscribe(string $sid, ?Cancellation $cancellation = null): void
    {
        $this->client->unsubscribe($sid, $cancellation);

        $subscriber = $this->subscribers[$sid] ?? null;
        $subscriber?->stop();

        unset($this->subscribers[$sid]);
    }
}
