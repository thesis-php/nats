<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Amp\Cancellation;
use Thesis\Nats\Client;
use Thesis\Nats\JetStream;
use Thesis\Nats\JetStream\Api\Router;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\NatsException;
use Thesis\Nats\Subscription;

/**
 * @api
 */
final readonly class Consumer
{
    /** @var non-empty-string */
    public string $name;

    /** @var non-empty-string */
    public string $stream;

    public function __construct(
        public Api\ConsumerInfo $info,
        private JetStream $js,
        private Client $nats,
        private Router $router,
        private Encoder $json,
    ) {
        $this->name = $info->name;
        $this->stream = $info->streamName;
    }

    public function pulling(): PullConsumer
    {
        return new PullConsumer(
            info: $this->info,
            nats: $this->nats,
            router: $this->router,
            json: $this->json,
        );
    }

    /**
     * @throws \LogicException
     */
    public function pushing(): PushConsumer
    {
        return new PushConsumer(
            info: $this->info,
            nats: $this->nats,
            deliverSubject: $this->info->config->deliverSubject ?? throw new \LogicException('For push consumers deliver subject is required.'),
        );
    }

    /**
     * @param callable(Delivery, Subscription): void $handler
     * @throws NatsException
     */
    public function pull(
        callable $handler,
        PullConsumeConfig $config = new PullConsumeConfig(),
        ?Cancellation $cancellation = null,
    ): Subscription {
        return $this
            ->pulling()
            ->consume($handler, $config, $cancellation);
    }

    /**
     * @param callable(Delivery, Subscription): void $handler
     * @throws \LogicException
     * @throws NatsException
     */
    public function push(
        callable $handler,
        PushConsumeConfig $config = new PushConsumeConfig(),
        ?Cancellation $cancellation = null,
    ): Subscription {
        return $this
            ->pushing()
            ->consume($handler, $config, $cancellation);
    }

    /**
     * @throws NatsException
     */
    public function actualInfo(): Api\ConsumerInfo
    {
        return $this->js->consumerInfo($this->stream, $this->name);
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
