<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 * @template T
 * @template-extends \IteratorAggregate<T>
 */
interface PaginatedResponse extends
    \IteratorAggregate,
    \Countable
{
    /**
     * @return non-negative-int
     */
    public function total(): int;
}
