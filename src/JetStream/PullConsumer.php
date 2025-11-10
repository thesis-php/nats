<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Amp\Cancellation;
use Thesis\Nats\Client;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\JetStream\Api\Router;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\NatsException;
use Thesis\Nats\Subscription;

/**
 * @api
 */
final class PullConsumer
{
    /** @var list<Subscription> */
    private array $subscribers = [];

    public function __construct(
        private readonly Api\ConsumerInfo $info,
        private readonly Client $nats,
        private readonly Router $router,
        private readonly Encoder $json,
    ) {}

    /**
     * @param callable(Delivery, Subscription): void $handler
     * @throws NatsException
     */
    public function consume(
        callable $handler,
        ConsumeConfig $config = new ConsumeConfig(),
        ?Cancellation $cancellation = null,
    ): Subscription {
        $id = Id\generateInboxId();

        $messageHandler = new Internal\PullMessageHandler(
            handler: $handler,
            nats: $this->nats,
            json: $this->json,
            config: $config,
            subject: $this->router->route(Api\ApiMethod::ConsumerMessageNext->compile($this->info->streamName, $this->info->name)),
            replyTo: $id,
        );

        $subscription = $this->nats->subscribe(
            subject: $id,
            handler: $messageHandler,
            cancellation: $cancellation,
        );

        $this->subscribers[] = $subscription->onComplete(
            $messageHandler->stop(...),
        );

        return $subscription;
    }

    public function unsubscribeAll(?Cancellation $cancellation = null): void
    {
        [$subscribers, $this->subscribers] = [$this->subscribers, []];

        foreach ($subscribers as $subscriber) {
            $subscriber->stop($cancellation);
        }
    }
}
