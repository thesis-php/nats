<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 * @template-implements PaginatedResponse<non-empty-string>
 */
final readonly class StreamNamesCollection implements PaginatedResponse
{
    /**
     * @param non-negative-int $total
     * @param non-negative-int $offset
     * @param non-negative-int $limit
     * @param ?list<non-empty-string> $streams
     */
    public function __construct(
        public int $total,
        public int $offset,
        public int $limit,
        public ?array $streams = null,
    ) {}

    public function total(): int
    {
        return $this->total;
    }

    public function getIterator(): \Traversable
    {
        yield from $this->streams ?? [];
    }

    public function count(): int
    {
        return \count($this->streams ?? []);
    }
}
