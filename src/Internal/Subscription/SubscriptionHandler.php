<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Subscription;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Pipeline;
use Revolt\EventLoop;
use Thesis\Nats\Delivery;
use Thesis\Nats\Exception\SlowConsumer;
use Thesis\Nats\PendingLimits;
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

    private readonly Backlog $backlog;

    private bool $inflight = false;

    /**
     * @param non-empty-string $subject
     * @param ?non-empty-string $queueGroup
     * @param \Closure(): void $unsubscribe
     * @param callable(Delivery, Subscription): void $handler
     * @param positive-int $bufferSize
     * @param \Closure(SlowConsumer): void $onSlowConsumer
     * @param ?positive-int $remaining the number of messages after which the server removes the subscription
     * @param ?\Closure(): void $onReceive called once a message arrives, before it waits for the handler
     */
    public function __construct(
        string $subject,
        ?string $queueGroup,
        \Closure $unsubscribe,
        callable $handler,
        int $bufferSize,
        private readonly \Closure $onSlowConsumer,
        private ?int $remaining = null,
        private readonly ?\Closure $onReceive = null,
        PendingLimits $pendingLimits = new PendingLimits(),
    ) {
        $this->backlog = $backlog = new Backlog($pendingLimits, $this->reportSlowConsumer(...));

        /** @var Pipeline\Queue<Delivery> $queue */
        $queue = new Pipeline\Queue($bufferSize);

        /** @var DeferredFuture<Operation<*>> */
        $completeSubscriptionDeferred = new DeferredFuture();

        /** @var DeferredFuture<void> */
        $completeSubscriptionMarker = new DeferredFuture();

        $this->mq = $mq = new MessageQueue($queue);
        $this->subscription = $subscription = new Subscription(
            $subject,
            $queueGroup,
            $completeSubscriptionMarker->getFuture(),
            static function (Operation $op) use (
                $unsubscribe,
                $completeSubscriptionDeferred,
            ): void {
                $unsubscribe();
                $completeSubscriptionDeferred->complete($op);
            },
            $backlog,
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
            $backlog,
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
                    /** @var Delivery $delivery */
                    $delivery = $op->value;
                    $backlog->deliver($delivery);
                    $inflight = true;

                    try {
                        $handler($delivery, $subscription);
                    } catch (\Throwable $e) {
                        try {
                            $unsubscribe();
                        } catch (\Throwable) {
                            // The connection is gone and so is the server-side subscription: the handler's error
                            // is the one to report, and it must not be lost in the event loop.
                        }

                        $backlog->clear();
                        $completeSubscriptionMarker->error($e);
                        $queue->complete();

                        return;
                    } finally {
                        $inflight = false;
                    }
                } else {
                    $queue->complete();

                    if ($op instanceof Error) {
                        $backlog->clear();
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
                            $backlog->deliver($delivery);

                            try {
                                $handler($delivery, $subscription);
                            } catch (\Throwable $e) {
                                $backlog->clear();
                                $completeSubscriptionMarker->error($e);

                                return;
                            }
                        }
                    }

                    $backlog->clear();
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
        if ($this->onReceive !== null) {
            ($this->onReceive)();
        }

        $last = $this->countDown();
        $accepted = $this->accept($delivery);

        if ($last && $accepted) {
            // The server has removed the subscription after this message: the handler finishes the received ones.
            $future = async($this->subscription->drain(...));
            $future->ignore();
        }

        return $accepted;
    }

    private function accept(Delivery $delivery): bool
    {
        // If we are busy processing a message, we are unlikely to be interested in receiving heartbeats since we cannot process them.
        // To prevent accumulating them in memory and avoid triggering a flood of watchdog interactions after processing completes,
        // we silently discard heartbeat messages.
        if ($this->inflight && ($delivery->message->headers?->isHeartbeat() ?? false)) {
            return true;
        }

        // Admitted before the push, which waits while the buffer is full: the waiting messages are pending as well.
        if (!$this->backlog->admit($delivery)) {
            return true;
        }

        if ($this->mq->push($delivery)) {
            return true;
        }

        $this->backlog->revoke($delivery);

        return false;
    }

    private function reportSlowConsumer(): void
    {
        ($this->onSlowConsumer)(SlowConsumer::forSubscription($this->subscription));
    }

    /**
     * @return bool whether it is the last message the server delivers to the subscription
     */
    private function countDown(): bool
    {
        if ($this->remaining === null) {
            return false;
        }

        if ($this->remaining > 1) {
            --$this->remaining;

            return false;
        }

        $this->remaining = null;

        return true;
    }
}
