<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\HeaderKey;

/**
 * @api
 * @template-implements HeaderKey<\DateTimeImmutable|non-empty-string>
 */
enum Schedule: string implements HeaderKey
{
    case Header = 'Nats-Schedule';

    public function encode(mixed $value): string
    {
        if ($value instanceof \DateTimeImmutable) {
            return "@at {$value->format(\DateTimeInterface::RFC3339)}";
        }

        return $value;
    }

    public function decode(string $value): \DateTimeImmutable|string
    {
        if (str_starts_with($value, '@at')) {
            $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::RFC3339, substr($value, 3));

            if ($date === false) {
                throw new \UnexpectedValueException("Unexpected time format: {$value}");
            }

            return $date;
        }

        if ($value === '') {
            throw new \UnexpectedValueException("Unexpected schedule format: {$value}");
        }

        return $value;
    }
}
