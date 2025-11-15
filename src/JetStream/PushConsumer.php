<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Amp\Cancellation;
use Thesis\Nats\Client;
use Thesis\Nats\Exception\ConsumerAlreadyConsuming;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\JetStream\Internal\Heartbeat;
use Thesis\Nats\NatsException;
use Thesis\Nats\Subscription;

/**
 * @api
 */
final class PushConsumer
{
    private ?Subscription $subscription = null;

    /**
     * @param non-empty-string $deliverSubject
     */
    public function __construct(
        public readonly Api\ConsumerInfo $info,
        private readonly Client $nats,
        private readonly string $deliverSubject,
    ) {}

    /**
     * @param callable(JetStreamDelivery, Subscription): void $handler
     * @throws NatsException
     */
    public function consume(
        callable $handler,
        PushConsumeConfig $config = new PushConsumeConfig(),
        ?Cancellation $cancellation = null,
    ): Subscription {
        if ($this->subscription !== null && !$this->subscription->completed()) {
            throw new ConsumerAlreadyConsuming();
        }

        $watchdog = new Heartbeat\Watchdog(
            $this->info->config->idleHeartbeat?->mul(2),
            $config->maxMissedHeartbeats,
        );

        $handler = new Internal\PushMessageHandler(
            handler: $handler,
            nc: $this->nats,
            watchdog: $watchdog,
        );

        $subscription = $this->nats->subscribe(
            subject: $this->deliverSubject,
            handler: $handler,
            queueGroup: $this->info->config->deliverGroup,
            cancellation: $cancellation,
        );

        $watchdog->subscribe($subscription->error(...));

        $subscription = $subscription->onComplete($watchdog->stop(...));

        return $this->subscription = $subscription;
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
     * @param \Closure(Subscription): void $do
     */
    private function complete(\Closure $do): void
    {
        try {
            if ($this->subscription !== null) {
                $do($this->subscription);
            }
        } finally {
            $this->subscription = null;
        }
    }
}
