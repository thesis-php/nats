<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Internal\Sync;

use Amp\Pipeline;

/**
 * @internal
 * @template-implements \IteratorAggregate<null>
 */
final class Barrier implements \IteratorAggregate
{
    /** @var Pipeline\ConcurrentIterator<null> */
    private readonly Pipeline\ConcurrentIterator $iterator;

    /** @var Pipeline\Queue<null> */
    private readonly Pipeline\Queue $queue;

    /** @var non-negative-int */
    private int $counter = 0;

    /**
     * @param positive-int $count
     */
    public function __construct(
        private readonly int $count,
    ) {
        /** @var Pipeline\Queue<null> $queue */
        $queue = new Pipeline\Queue(bufferSize: 1);

        $this->queue = $queue;
        $this->iterator = $queue->iterate();
    }

    public function dispatch(): void
    {
        $this->queue->push(null);
        $this->counter = 0;
    }

    public function arrive(): void
    {
        if (++$this->counter >= $this->count) {
            $this->dispatch();
        }
    }

    public function close(): void
    {
        if (!$this->queue->isComplete()) {
            $this->queue->complete();
            $this->counter = 0;
        }
    }

    public function getIterator(): \Traversable
    {
        return $this->iterator->getIterator();
    }
}
