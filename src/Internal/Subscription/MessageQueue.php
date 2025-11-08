<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Subscription;

use Amp\Pipeline;
use Thesis\Nats\Internal\Iter;

/**
 * @internal
 * @template-covariant T
 * @template-implements \IteratorAggregate<T>
 */
final readonly class MessageQueue implements \IteratorAggregate
{
    /** @var Pipeline\ConcurrentIterator<T> */
    private Pipeline\ConcurrentIterator $iterator;

    /**
     * @param Pipeline\Queue<T> $queue
     */
    public function __construct(
        private Pipeline\Queue $queue,
    ) {
        $this->iterator = $queue->iterate();
    }

    public function push(mixed $value): bool
    {
        return Iter\push($this->queue, $value);
    }

    /**
     * @return Operation<T>
     */
    public function pop(): Operation
    {
        if ($this->iterator->continue()) {
            return new Emit($this->iterator->getValue());
        }

        return Stop::It;
    }

    public function getIterator(): \Traversable
    {
        return $this->iterator;
    }
}
