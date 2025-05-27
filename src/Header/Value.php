<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\HeaderKey;

/**
 * @api
 * @template ValueType of string = string
 * @template-implements HeaderKey<ValueType>
 */
final readonly class Value implements HeaderKey, \Stringable
{
    /** @var callable(ValueType): string */
    private mixed $encode;

    /** @var callable(string): ValueType */
    private mixed $decode;

    /**
     * @param non-empty-string $name
     * @param ?callable(ValueType): string $encode
     * @param ?callable(string): ValueType $decode
     */
    public function __construct(
        private string $name,
        ?callable $encode = null,
        ?callable $decode = null,
    ) {
        $this->encode = $encode ?? static fn(string $value): string => $value;
        $this->decode = $decode ?? static function (string $value): string {
            /** @var ValueType */
            return $value;
        };
    }

    public function encode(mixed $value): string
    {
        return ($this->encode)($value);
    }

    public function decode(string $value): string
    {
        return ($this->decode)($value);
    }

    /**
     * @return non-empty-string
     */
    public function __toString(): string
    {
        return $this->name;
    }
}
