<?php

declare(strict_types=1);

namespace Thesis\Nats\Iterator;

/**
 * @api
 * @template T
 * @template-implements Outcome<T>
 * @template-implements \IteratorAggregate<Outcome<T>>
 */
final readonly class Composite implements Outcome, \IteratorAggregate
{
    /** @var list<Outcome<T>> */
    public array $operations;

    /**
     * @param Outcome<T> ...$operations
     */
    public function __construct(Outcome ...$operations)
    {
        $this->operations = array_values($operations);
    }

    /**
     * @param Outcome<T> $operation
     * @return self<T>
     */
    public function with(Outcome $operation): self
    {
        return new self($operation, ...$this->operations);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->operations;
    }
}
