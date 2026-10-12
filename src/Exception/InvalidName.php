<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\NatsException;

/**
 * Thrown before anything is sent when a stream, consumer, counter or micro endpoint name is malformed.
 *
 * @api
 */
final class InvalidName extends NatsException
{
    public static function because(string $value, string $reason): self
    {
        return new self("Invalid name '{$value}': {$reason}.");
    }
}
