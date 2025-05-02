<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\NatsException;

/**
 * @api
 */
final class FeatureIsNotSupported extends \RuntimeException implements NatsException
{
    /**
     * @param non-empty-string $serverVersion
     */
    public static function forHeaders(string $serverVersion): self
    {
        return new self("Headers is not supported by this Nats version '{$serverVersion}'.");
    }
}
