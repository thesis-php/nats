<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

/**
 * @internal
 */
interface ConnectionFactory
{
    /**
     * @throws \Exception
     */
    public function connect(): Connection;
}
