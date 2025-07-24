<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal;

use Amp\Cancellation;
use Amp\Pipeline\ConcurrentIterator;
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
     * @param \Closure(?Cancellation=): void $complete
     * @param \Closure(\Throwable, ?Cancellation=): void $cancel
     */
    public function __construct(
        private ConcurrentIterator $iterator,
        private \Closure $complete,
        private \Closure $cancel,
    ) {}

    public function complete(?Cancellation $cancellation = null): void
    {
        ($this->complete)($cancellation);
    }

    public function cancel(\Throwable $e, ?Cancellation $cancellation = null): void
    {
        ($this->cancel)($e, $cancellation);
    }

    public function subscribe(callable $handler): callable
    {
        $iterator = $this->iterator;

        EventLoop::queue(static function () use ($iterator, $handler): void {
            foreach ($iterator as $value) {
                $handler($value);
            }
        });

        [$cancel, $complete] = [$this->cancel, $this->complete];

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
