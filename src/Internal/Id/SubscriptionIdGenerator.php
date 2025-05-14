<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Id;

/**
 * @api
 */
final class SubscriptionIdGenerator
{
    /** @var non-negative-int */
    private int $counter = 0;

    /**
     * @return non-empty-string
     */
    public function nextId(): string
    {
        $counter = ++$this->counter;

        return "{$counter}";
    }
}
