<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Rpc;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Pipeline;
use Thesis\Nats\Delivery;
use Thesis\Nats\Exception\RequestHasNoResponders;
use Thesis\Nats\Internal\Iter;
use Thesis\Nats\Internal\Timer;
use Thesis\Nats\Status;
use Thesis\Nats\Subscription;
use function Amp\async;

/**
 * Handles the inbox subscription of a scatter-gather request (ADR-47). The arrival of the replies, which the stall
 * is counted from, is tracked apart from passing them to the consumer: the latter waits while the consumer is busy,
 * and a slow consumer must not look like silent responders. The maximum number of replies is left to the subscription
 * itself (UNSUB <sid> <max>).
 *
 * @internal
 */
final class RequestManyHandler
{
    private ?Subscription $subscription = null;

    private bool $answered = false;

    private ?Timer $stallTimer = null;

    private ?Timer $timeoutTimer = null;

    private ?string $cancellationId = null;

    /**
     * @param Pipeline\Queue<Delivery> $queue
     * @param ?float $stall in seconds
     * @param ?\Closure(Delivery): bool $sentinel
     */
    public function __construct(
        private readonly Pipeline\Queue $queue,
        private readonly ?float $stall = null,
        private readonly ?\Closure $sentinel = null,
    ) {}

    /**
     * Starts the clock once the inbox subscription exists, before the request is published.
     *
     * @param ?float $timeout in seconds
     */
    public function start(Subscription $subscription, ?float $timeout = null, ?Cancellation $cancellation = null): void
    {
        $this->subscription = $subscription;

        // Whatever ends the gathering, the timers and the cancellation must not outlive it.
        $future = async(function () use (
            $subscription,
            $cancellation,
        ): void {
            try {
                $subscription->awaitCompletion();
            } catch (\Throwable) {
                // Reported to the consumer through the iterator.
            } finally {
                $this->stallTimer?->cancel();
                $this->timeoutTimer?->cancel();

                if ($this->cancellationId !== null) {
                    $cancellation?->unsubscribe($this->cancellationId);
                }
            }
        });
        $future->ignore();

        if ($timeout !== null) {
            $this->timeoutTimer = Timer::delay($timeout, static fn() => self::end($subscription));
        }

        $this->cancellationId = $cancellation?->subscribe(static function (CancelledException $e) use ($subscription): void {
            $future = async($subscription->error(...), $e);
            $future->ignore();
        });
    }

    /**
     * Called once a reply arrives, however busy the consumer is: the stall is counted between the replies only.
     */
    public function received(): void
    {
        $this->stallTimer?->cancel();

        $subscription = $this->subscription;

        if ($this->stall !== null && $subscription !== null) {
            $this->stallTimer = Timer::delay($this->stall, static fn() => self::end($subscription));
        }
    }

    /**
     * Passes the reply to the consumer, waiting while the consumer is busy.
     *
     * @throws RequestHasNoResponders if the first reply says nobody listens
     */
    public function __invoke(Delivery $delivery, Subscription $subscription): void
    {
        // Completed by the sentinel or by the consumer who stopped iterating: the rest of the replies are not needed.
        if ($this->queue->isComplete()) {
            return;
        }

        if (!$this->answered) {
            $this->answered = true;

            if ($delivery->message->headers?->statusCode() === Status::NoResponders) {
                throw new RequestHasNoResponders();
            }
        }

        if ($this->sentinel !== null && ($this->sentinel)($delivery)) {
            $this->queue->complete();
            $subscription->stop();

            return;
        }

        Iter\push($this->queue, $delivery);
    }

    /**
     * Ends the gathering on a timer: the replies already received are still yielded.
     */
    private static function end(Subscription $subscription): void
    {
        $future = async($subscription->drain(...));
        $future->ignore();
    }
}
