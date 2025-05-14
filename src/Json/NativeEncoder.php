<?php

declare(strict_types=1);

namespace Thesis\Nats\Json;

/**
 * @api
 */
final readonly class NativeEncoder implements Encoder
{
    public function __construct(
        private int $flags = 0,
    ) {}

    public function encode(mixed $value): string
    {
        if ($value === []) {
            return '{}';
        }

        return json_encode(
            value: $value,
            flags: $this->flags | JSON_THROW_ON_ERROR,
        );
    }

    public function decode(string $value): array
    {
        /** @var array<non-empty-string, mixed> */
        return json_decode(
            json: $value,
            associative: true,
            flags: $this->flags | JSON_THROW_ON_ERROR,
        );
    }
}
