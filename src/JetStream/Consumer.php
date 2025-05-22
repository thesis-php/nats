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
final readonly class Consumer
{
    /**
     * @param non-empty-string $name
     * @param non-empty-string $stream
     */
    public function __construct(
        public Api\ConsumerInfo $info,
        public string $name,
        public string $stream,
        private JetStream $js,
        private Client $client,
        private Router $router,
        private Encoder $json,
    ) {}

    /**
     * @param callable(Delivery): void $handler
     * @throws NatsException
     */
    public function consume(
        callable $handler,
        ConsumeConfig $config = new ConsumeConfig(),
        ?Cancellation $cancellation = null,
    ): void {
        $id = Id\generateInboxId();

        $this->client->subscribe(
            subject: $id,
            handler: new Internal\MessageHandler(
                handler: $handler,
                client: $this->client,
                json: $this->json,
                config: $config,
                subject: $this->router->route(Api\ApiMethod::ConsumerMessageNext->compile($this->stream, $this->name)),
                replyTo: $id,
            ),
            cancellation: $cancellation,
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
}
