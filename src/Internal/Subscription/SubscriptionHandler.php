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
                /** @var Future<Emit<Delivery>> $pop */
                $pop = async($mq->pop(...));

                $op = Future\awaitFirst([
                    $completeSubscriptionDeferred->getFuture(),
                    $pop,
                ]);

                if ($op instanceof Emit) {
                    try {
                        /** @phpstan-ignore argument.type */
                        $handler($op->value, $subscription);
                    } catch (\Throwable $e) {
                        $unsubscribe();
                        $completeSubscriptionMarker->error($e);
                        $queue->complete();

                        return;
                    }
                } else {
                    $queue->complete();

                    if ($op instanceof Drain) {
                        $messages = [...$mq];

                        if ($pop->isComplete()) {
                            $messages = [$pop->await()->value, ...$messages];
                        }

                        foreach ($messages as $delivery) {
                            try {
                                $handler($delivery, $subscription);
                            } catch (\Throwable $e) {
                                $unsubscribe();
                                $completeSubscriptionMarker->error($e);

                                return;
                            }
                        }
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
