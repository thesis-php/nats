<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\NatsException;

/**
 * @api
 */
final class FeatureIsNotSupported extends NatsException
{
    /**
     * @param non-empty-string $serverVersion
     */
    public static function forHeaders(string $serverVersion): self
    {
        return new self("Headers is not supported by this Nats version '{$serverVersion}'.");
    }

    /**
     * @param non-empty-string $serverVersion
     */
    public static function forJetStream(string $serverVersion): self
    {
        return new self("JetStream is not supported by this Nats version '{$serverVersion}'.");
    }

    public static function forLimitMarkerTtl(): self
    {
        return new self('Limit marker ttl for KeyValue is not supported.');
    }
}
