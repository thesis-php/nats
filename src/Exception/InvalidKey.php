<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\NatsException;

/**
 * Thrown before anything is sent when a key-value key or key filter is malformed.
 *
 * @api
 */
final class InvalidKey extends NatsException
{
    public static function because(string $value, string $reason): self
    {
        return new self("Invalid key '{$value}': {$reason}.");
    }
}
