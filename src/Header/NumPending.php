<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\HeaderKey;

/**
 * @api
 * @template-implements HeaderKey<non-negative-int>
 */
enum NumPending: string implements HeaderKey
{
    case Header = 'Nats-Num-Pending';

    public function encode(mixed $value): string
    {
        return (string) $value;
    }

    public function decode(string $value): int
    {
        return max((int) $value, 0);
    }
}
