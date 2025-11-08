<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Subscription;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Pipeline;
use Revolt\EventLoop;
use Thesis\Nats\Delivery;
use Thesis\Nats\Subscription;
use function Amp\async;

/**
 * @internal
 */
final readonly class SubscriptionHandler
{
    public Subscription $subscription;

    /** @var MessageQueue<Delivery> */
    private MessageQueue $mq;

    /**
     * @param \Closure(): void $unsubscribe
     * @param callable(Delivery, Subscription): void $handler
     * @param positive-int $bufferSize
     */
    public function __construct(
        \Closure $unsubscribe,
        callable $handler,
        int $bufferSize,
    ) {
        /** @var Pipeline\Queue<Delivery> $queue */
        $queue = new Pipeline\Queue($bufferSize);

        /** @var DeferredFuture<Operation<never>> */
        $completeSubscriptionDeferred = new DeferredFuture();

        /** @var DeferredFuture<void> */
        $completeSubscriptionMarker = new DeferredFuture();

        $this->mq = $mq = new MessageQueue($queue);
        $this->subscription = $subscription = new Subscription(
            $completeSubscriptionMarker->getFuture(),
            static function (Operation $op) use (
                $unsubscribe,
                $completeSubscriptionDeferred,
            ): void {
                $unsubscribe();
                $completeSubscriptionDeferred->complete($op);
            },
        );

        EventLoop::queue(static function () use (
            $queue,
            $completeSubscriptionDeferred,
            $completeSubscriptionMarker,
            $mq,
            $subscription,
            $handler,
            $unsubscribe,
        ): void {
            while (!$queue->isComplete()) {
                $op = Future\awaitFirst([
                    $completeSubscriptionDeferred->getFuture(),
                    async($mq->pop(...)),
                ]);

                if ($op instanceof Emit) {
                    try {
                        /** @phpstan-ignore argument.type */
                        $handler($op->value, $subscription);
                    } catch (\Throwable $e) {
                        $unsubscribe();
                        $completeSubscriptionMarker->error($e);

                        return;
                    }
                } else {
                    $queue->complete();

                    if ($op instanceof Drain) {
                        // We process messages concurrently to avoid blocking,
                        // but confine the processing to a single coroutine to maintain message ordering.
                        EventLoop::queue(static function () use (
                            $mq,
                            $handler,
                            $subscription,
                            $completeSubscriptionMarker,
                            $unsubscribe,
                        ): void {
                            foreach ($mq as $delivery) {
                                try {
                                    $handler($delivery, $subscription);
                                } catch (\Throwable $e) {
                                    $unsubscribe();
                                    $completeSubscriptionMarker->error($e);

                                    return;
                                }
                            }

                            $completeSubscriptionMarker->complete();
                        });

                        return;
                    }

                    $completeSubscriptionMarker->complete();
                }
            }
        });
    }

    /**
     * @return bool returns false if the queue has been closed, which should signal the client to remove the subscription
     */
    public function push(Delivery $delivery): bool
    {
        return $this->mq->push($delivery);
    }
}
