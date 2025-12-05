<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Amp\Cancellation;
use Amp\Future;
use Amp\Pipeline;
use Revolt\EventLoop;
use Thesis\Nats\Client;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\JetStream\Api\PullRequest;
use Thesis\Nats\JetStream\Api\Router;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Nats\JetStream\Internal\Heartbeat;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\Message;
use Thesis\Nats\NatsException;
use Thesis\Nats\Subscription;
use Thesis\Time\TimeSpan;
use function Amp\async;

/**
 * @api
 */
final class PullConsumer
{
    /** @var list<Subscription> */
    private array $subscriptions = [];

    /** @var non-empty-string */
    private readonly string $subject;

    public function __construct(
        private readonly Api\ConsumerInfo $info,
        private readonly Client $nats,
        Router $router,
        private readonly Encoder $json,
    ) {
        $this->subject = $router->route(Api\ApiMethod::ConsumerMessageNext->compile(
            $this->info->streamName,
            $this->info->name,
        ));
    }

    /**
     * @return DeliveryBatch<JetStreamDelivery>
     */
    public function fetch(FetchConfig $config, ?Cancellation $cancellation = null): DeliveryBatch
    {
        /** @var Pipeline\Queue<JetStreamDelivery> $queue */
        $queue = new Pipeline\Queue($config->batch);

        $watchdog = new Heartbeat\Watchdog(
            time: $config->heartbeat->mul(2),
            heartbeatsThreshold: 1,
        );

        $handler = new Internal\FetchMessageHandler(
            config: $config,
            nc: $this->nats,
            queue: $queue,
            watchdog: $watchdog,
        );

        $subscription = $this->nats->subscribe(
            subject: $reply = Id\generateInboxId(),
            handler: $handler,
            bufferSize: $config->batch,
            cancellation: $cancellation,
        );

        $this->nats->publish(
            subject: $this->subject,
            message: new Message($this->json->encode(new PullRequest(
                expires: $config->maxWait,
                batch: $config->batch,
                maxBytes: $config->maxBytes,
                noWait: $config->noWait,
                heartbeat: $config->heartbeat,
            ))),
            replyTo: $reply,
        );

        if ($config->noWait !== true) {
            $callbackId = EventLoop::delay(
                $config->maxWait->add(TimeSpan::fromSeconds(1))->toSeconds(),
                static fn() => $subscription->stop(),
            );

            $subscription = $subscription->onComplete(
                static fn() => EventLoop::cancel($callbackId),
            );
        }

        $watchdog->subscribe($subscription->error(...));

        return new DeliveryBatch(
            $queue->pipe(),
            $subscription->onComplete(
                $queue->complete(...),
                $watchdog->stop(...),
            ),
        );
    }

    /**
     * @param callable(JetStreamDelivery, Subscription): void $handler
     * @throws NatsException
     */
    public function consume(
        callable $handler,
        PullConsumeConfig $config = new PullConsumeConfig(),
        ?Cancellation $cancellation = null,
    ): Subscription {
        if (\count($priorityGroups = $this->info->config->priorityGroups ?? []) > 0) {
            if ($config->group === null) {
                throw new \LogicException('Priority group is required for priority consumer.');
            }

            if (!\in_array($config->group, $priorityGroups, true)) {
                throw new \LogicException(\sprintf('Priority group "%s" must be one of: "%s".', $config->group, implode(', ', $priorityGroups)));
            }
        } elseif ($config->group !== null) {
            throw new \LogicException('Priority group can only be used for priority consumer.');
        }

        $reply = Id\generateInboxId();

        $messageHandler = new Internal\PullMessageHandler(
            handler: $handler,
            nc: $this->nats,
            config: $config,
            json: $this->json,
            subject: $this->subject,
            reply: $reply,
        );

        $subscription = $this->nats->subscribe(
            subject: $reply,
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
