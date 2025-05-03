<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Thesis\Nats\Internal\Hooks;
use Thesis\Nats\Internal\Protocol;
use Thesis\Nats\NatsException;

/**
 * @internal
 */
interface Connection
{
    /**
     * @throws NatsException
     */
    public function execute(Protocol\Frame $frame): void;

    public function hooks(): Hooks\Provider;

    /**
     * @throws NatsException
     */
    public function info(): ConnectionInfo;

    public function close(): void;
}
