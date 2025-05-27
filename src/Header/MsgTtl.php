<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\HeaderKey;
use Thesis\Time\TimeSpan;

/**
 * Used to specify the TTL for a specific message. This will override the default TTL for the stream.
 *
 * @api
 * @template-implements HeaderKey<TimeSpan>
 */
enum MsgTtl: string implements HeaderKey
{
    case Header = 'Nats-TTL';

    public function encode(mixed $value): string
    {
        return match ($secs = $value->toSeconds()) {
            -1 => 'never',
            default => (string) $secs,
        };
    }

    public function decode(string $value): TimeSpan
    {
        return match ($value) {
            'never' => TimeSpan::fromSeconds(-1),
            default => TimeSpan::fromSeconds((int) $value),
        };
    }
}
