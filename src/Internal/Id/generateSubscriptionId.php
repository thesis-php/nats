<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Id;

/**
 * @internal
 * @return non-empty-string
 */
function generateSubscriptionId(): string
{
    /** @var non-negative-int $counter */
    static $counter = 0;
    ++$counter;

    return "{$counter}";
}
