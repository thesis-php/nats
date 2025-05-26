<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\HeaderKey;

/**
 * @api
 * @template ValueType of string = string
 * @template-implements HeaderKey<ValueType>
 */
final readonly class Primitive implements HeaderKey, \Stringable
{
    /**
     * @param non-empty-string|\BackedEnum $name
     */
    public function __construct(
        private string|\BackedEnum $name,
    ) {}

    public function encode(mixed $value): string
    {
        return $value;
    }

    public function decode(string $value): string
    {
        /** @var ValueType */
        return $value;
    }

    /**
     * @return non-empty-string
     */
    public function __toString(): string
    {
        $key = $this->name;

        if ($key instanceof \BackedEnum) {
            /** @var non-empty-string */
            $key = $key->value;
        }

        return $key;
    }
}
