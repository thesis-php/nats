<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 * @template-implements \IteratorAggregate<non-empty-string>
 */
final readonly class StreamNamesCollection implements \IteratorAggregate
{
    /**
     * @param non-negative-int $total
     * @param non-negative-int $offset
     * @param non-negative-int $limit
     * @param list<non-empty-string> $streams
     */
    public function __construct(
        public int $total,
        public int $offset,
        public int $limit,
        public array $streams,
    ) {}

    public function getIterator(): \Traversable
    {
        yield from $this->streams;
    }
}
