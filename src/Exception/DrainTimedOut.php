<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\Config;
use Thesis\Nats\NatsException;

/**
 * The subscriptions did not finish draining within {@see Config::$drainTimeout}: the connection is closed anyway.
 *
 * @api
 */
final class DrainTimedOut extends NatsException
{
    public static function after(float $timeout, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('The client did not drain in %ss, the connection is closed.', $timeout), previous: $previous);
    }
}
