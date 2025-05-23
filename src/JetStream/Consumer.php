<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Amp\Cancellation;
use Thesis\Nats\Client;
use Thesis\Nats\Internal\Id;
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
     * @param callable(Delivery): void $handler
     * @return callable(): void
     * @throws NatsException
     */
    public function consume(
        callable $handler,
        ConsumeConfig $config = new ConsumeConfig(),
        ?Cancellation $cancellation = null,
    ): callable {
        $id = Id\generateInboxId();

        $messageHandler = new Internal\MessageHandler(
            handler: $handler,
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

        return function () use ($sid, $messageHandler): void {
            $this->client->unsubscribe($sid);
            unset($this->subscribers[$sid]);

            $messageHandler->stop();
        };
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
}
