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
        if (str_starts_with($value, '@at ')) {
            $time = trim(substr($value, 4));

            if ($time === '') {
                throw new \UnexpectedValueException("Unexpected time format: {$value}");
            }

            try {
                return new \DateTimeImmutable($time);
            } catch (\Exception $e) {
                throw new \UnexpectedValueException("Unexpected time format: {$value}", previous: $e);
            }
        }

        if ($value === '') {
            throw new \UnexpectedValueException("Unexpected schedule format: {$value}");
        }

        return $value;
    }
}
