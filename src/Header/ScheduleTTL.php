<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\HeaderKey;

/**
 * @api
 * @template-implements HeaderKey<non-empty-string|non-negative-int>
 */
enum ScheduleTTL: string implements HeaderKey
{
    case Header = 'Nats-Schedule-TTL';

    public function encode(mixed $value): string
    {
        return (string) $value;
    }

    public function decode(string $value): string
    {
        return $value ?: throw new \UnexpectedValueException("Invalid schedule ttl format: {$value}");
    }
}
