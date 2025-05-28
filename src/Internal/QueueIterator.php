<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal;

use Amp\Cancellation;
use Amp\Pipeline\ConcurrentIterator;
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

    public function getIterator(): \Traversable
    {
        return $this->iterator->getIterator();
    }
}
