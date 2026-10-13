<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Amp\Cancellation;
use Thesis\Nats\Internal\Hooks;
use Thesis\Nats\Internal\Protocol;
use Thesis\Nats\NatsException;

/**
 * @internal
 */
interface Connection
{
    /**
     * Writes the frames at once, in verbose mode then waits for the acknowledgements of all of them.
     *
     * @param Protocol\Frame|non-empty-list<Protocol\Frame> $frames
     * @throws NatsException
     */
    public function execute(Protocol\Frame|array $frames, ?Cancellation $cancellation = null): void;

    public function hooks(): Hooks\Provider;

    /**
     * @throws NatsException
     */
    public function info(): ConnectionInfo;

    public function close(): void;
}
