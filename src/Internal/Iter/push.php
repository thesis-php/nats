<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Iter;

use Amp\Pipeline;

/**
 * @internal
 * @template T
 * @param Pipeline\Queue<T> $queue
 * @param T $value
 */
function push(Pipeline\Queue $queue, mixed $value): bool
{
    $completed = $queue->isComplete();

    if (!$completed) {
        $queue->push($value);
    }

    return !$completed;
}
