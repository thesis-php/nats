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
        return json_encode(
            value: $value,
            flags: $this->flags | JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT,
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
