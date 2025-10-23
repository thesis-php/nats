<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro\Internal;

/**
 * @internal
 */
enum Verb: string
{
    case Ping = 'PING';
    case Stats = 'STATS';
    case Info = 'INFO';

    /**
     * @return non-empty-string
     */
    public function all(): string
    {
        return "{$this->value}-all";
    }

    /**
     * @return non-empty-string
     */
    public function kind(): string
    {
        return "{$this->value}-kind";
    }
}
