<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Thesis\Nats\Exception\ServerIsNotAvailable;

/**
 * @internal
 */
interface ConnectionFactory
{
    /**
     * Connects to the server and completes the handshake.
     *
     * @throws ServerIsNotAvailable if the server cannot be reached or does not complete the handshake in time
     * @throws \Exception
     */
    public function connect(Server $server): Connection;
}
