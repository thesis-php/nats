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
final class SubscriptionHandler
{
    public readonly Subscription $subscription;

    /** @var MessageQueue<Delivery> */
    private readonly MessageQueue $mq;

    private bool $inflight = false;

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

        /** @var DeferredFuture<Operation<*>> */
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

        $inflight = &$this->inflight;

        EventLoop::queue(static function () use (
            $queue,
            $completeSubscriptionDeferred,
            $completeSubscriptionMarker,
            $mq,
            $subscription,
            $handler,
            $unsubscribe,
            &$inflight,
        ): void {
            while (!$queue->isComplete()) {
                /** @var Future<Stop|Emit<Delivery>> $pop */
                $pop = async($mq->pop(...));

                $op = Future\awaitFirst([
                    $completeSubscriptionDeferred->getFuture(),
                    $pop,
                ]);

                if ($op instanceof Emit) {
                    $inflight = true;

                    try {
                        /** @phpstan-ignore argument.type */
                        $handler($op->value, $subscription);
                    } catch (\Throwable $e) {
                        $unsubscribe();
                        $completeSubscriptionMarker->error($e);
                        $queue->complete();

                        return;
                    } finally {
                        $inflight = false;
                    }
                } else {
                    $queue->complete();

                    if ($op instanceof Error) {
                        $completeSubscriptionMarker->error($op->exception);

                        return;
                    }

                    if ($op instanceof Drain) {
                        $messages = [...$mq];

                        if ($pop->isComplete()) {
                            $op = $pop->await();

                            // When complete a subscription, even if the complete future resolves first,
                            // it is possible for a pop future to resolve simultaneously if both events are triggered at the same time.
                            // Consequently, the drain logic must also process any such received message.
                            $messages = [...($op instanceof Emit ? [$op->value] : []), ...$messages];
                        }

                        foreach ($messages as $delivery) {
                            try {
                                $handler($delivery, $subscription);
                            } catch (\Throwable $e) {
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
        // If we are busy processing a message, we are unlikely to be interested in receiving heartbeats since we cannot process them.
        // To prevent accumulating them in memory and avoid triggering a flood of watchdog interactions after processing completes,
        // we silently discard heartbeat messages.
        if ($this->inflight && ($delivery->message->headers?->isHeartbeat() ?? false)) {
            return true;
        }

        return $this->mq->push($delivery);
    }
}
