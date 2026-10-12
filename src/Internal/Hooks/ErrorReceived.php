<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Hooks;

use Thesis\Nats\Internal\Protocol;

/**
 * A server error (-ERR) that was not an acknowledgement of a particular command.
 *
 * @internal
 */
final readonly class ErrorReceived
{
    public function __construct(
        public Protocol\Err $error,
    ) {}
}
