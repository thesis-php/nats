<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

use Amp\Pipeline\ConcurrentIterator;

/**
 * @api
 * @template-implements \IteratorAggregate<non-empty-string>
 */
final readonly class StoredObject implements \IteratorAggregate
{
    /**
     * @param ConcurrentIterator<non-empty-string> $iterator
     */
    public function __construct(
        public ObjectInfo $info,
        private ConcurrentIterator $iterator,
    ) {}

    public function __toString(): string
    {
        return implode(separator: '', array: [...$this]);
    }

    public function getIterator(): \Traversable
    {
        return $this->iterator->getIterator();
    }
}
