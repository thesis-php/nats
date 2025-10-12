<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal;

use Amp\Cancellation;
use Amp\Pipeline\ConcurrentIterator;
use Amp\Pipeline\Queue;
use Revolt\EventLoop;
use Thesis\Nats\Iterator;

/**
 * @internal
 * @template-covariant T
 * @template-implements Iterator<T>
 */
final readonly class QueueIterator implements Iterator
{
    /**
     * @param ConcurrentIterator<T> $iterator
     * @param Queue<T> $queue
     * @param \Closure(?Cancellation=): void $unsubscribe
     */
    public function __construct(
        private ConcurrentIterator $iterator,
        private Queue $queue,
        private \Closure $unsubscribe,
    ) {}

    public function complete(?Cancellation $cancellation = null): void
    {
        ($this->unsubscribe)($cancellation);

        if (!$this->queue->isComplete()) {
            $this->queue->complete();
        }
    }

    public function cancel(\Throwable $e, ?Cancellation $cancellation = null): void
    {
        ($this->unsubscribe)($cancellation);

        if (!$this->queue->isComplete()) {
            $this->queue->error($e);
        }
    }

    public function subscribe(callable $handler): callable
    {
        $iterator = $this->iterator;

        EventLoop::queue(static function () use ($iterator, $handler): void {
            foreach ($iterator as $value) {
                $handler($value);
            }
        });

        [$cancel, $complete] = [$this->cancel(...), $this->complete(...)];

        return static function (
            ?\Throwable $e = null,
            ?Cancellation $cancellation = null,
        ) use ($cancel, $complete): void {
            if ($e !== null) {
                $cancel($e, $cancellation);
            } else {
                $complete($cancellation);
            }
        };
    }

    public function getIterator(): \Traversable
    {
        return $this->iterator->getIterator();
    }
}
