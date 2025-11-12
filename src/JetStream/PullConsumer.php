<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Amp\Cancellation;
use Amp\Future;
use Thesis\Nats\Client;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\JetStream\Api\Router;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\NatsException;
use Thesis\Nats\Subscription;
use function Amp\async;

/**
 * @api
 */
final class PullConsumer
{
    /** @var list<Subscription> */
    private array $subscriptions = [];

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
        PullConsumeConfig $config = new PullConsumeConfig(),
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

        $this->subscriptions[] = $subscription->onComplete(
            $messageHandler->stop(...),
        );

        return $subscription;
    }

    public function stop(?Cancellation $cancellation = null): void
    {
        $this->complete(static fn(Subscription $subscription) => $subscription->stop($cancellation));
    }

    public function drain(?Cancellation $cancellation = null): void
    {
        $this->complete(static fn(Subscription $subscription) => $subscription->drain($cancellation));
    }

    /**
     * @param \Closure(Subscription): void $complete
     */
    private function complete(\Closure $complete): void
    {
        [$subscriptions, $this->subscriptions] = [$this->subscriptions, []];

        /** @var list<Future<void>> $futures */
        $futures = [];

        foreach ($subscriptions as $subscription) {
            $futures[] = async($complete, $subscription);
        }

        Future\awaitAll($futures);
    }
}
