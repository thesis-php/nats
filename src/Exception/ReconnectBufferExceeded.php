<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\Config;
use Thesis\Nats\NatsException;

/**
 * The client is reconnecting and the publishes buffered meanwhile have reached {@see Config::$reconnectBufferSize}.
 *
 * @api
 */
final class ReconnectBufferExceeded extends NatsException
{
    /**
     * @param non-negative-int $size
     */
    public static function forSize(int $size): self
    {
        return new self(\sprintf('The client is reconnecting and the publishes buffered meanwhile exceed %d bytes.', $size));
    }
}
