<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\HeaderKey;

/**
 * @api
 * @template-implements HeaderKey<int>
 */
enum UpToSequence: string implements HeaderKey
{
    case Header = 'Nast-UpTo-Sequence';

    public function encode(mixed $value): string
    {
        return (string) $value;
    }

    public function decode(string $value): int
    {
        return (int) $value;
    }
}
