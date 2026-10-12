<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\NatsException;

/**
 * Thrown before anything is sent when a key-value or object store bucket name is malformed.
 *
 * @api
 */
final class InvalidBucketName extends NatsException
{
    public static function because(string $value, string $reason): self
    {
        return new self("Invalid bucket name '{$value}': {$reason}.");
    }
}
