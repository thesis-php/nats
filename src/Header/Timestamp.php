<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\HeaderKey;

/**
 * @api
 * @template-implements HeaderKey<\DateTimeImmutable>
 */
enum Timestamp: string implements HeaderKey
{
    case Header = 'Nats-Time-Stamp';

    public function encode(mixed $value): string
    {
        return $value->format('Y-m-d\TH:i:s.') . str_pad($value->format('u'), 9, '0') . 'Z';
    }

    public function decode(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value);
    }
}
