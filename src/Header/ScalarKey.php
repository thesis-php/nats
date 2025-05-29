<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\HeaderKey;

/**
 * @api
 * @template ValueType = string
 * @template-implements HeaderKey<ValueType>
 */
final readonly class ScalarKey implements
    HeaderKey,
    \Stringable
{
    /**
     * @param non-empty-string $name
     * @return self<bool>
     */
    public static function bool(string $name): self
    {
        /** @var self<bool> */
        return new self(
            name: $name,
            encode: static fn(bool $value): string => (string) (int) $value,
            decode: static fn(string $value): bool => filter_var($value, FILTER_VALIDATE_BOOL),
        );
    }

    /**
     * @param non-empty-string $name
     * @return self<int>
     */
    public static function int(string $name): self
    {
        /** @var self<int> */
        return new self(
            name: $name,
            encode: \strval(...),
            decode: \intval(...),
        );
    }

    /**
     * @param non-empty-string $name
     * @return self<non-empty-string>
     */
    public static function nonEmptyString(string $name): self
    {
        /** @var self<non-empty-string> */
        return new self(
            name: $name,
            encode: \strval(...),
            decode: static function (string $value) use ($name): string {
                if ($value === '') {
                    throw new \InvalidArgumentException("The '{$name}' header value cannot be empty.");
                }

                return $value;
            },
        );
    }

    /**
     * @param non-empty-string $name
     */
    public static function string(string $name): self
    {
        return new self(
            name: $name,
            encode: \strval(...),
            decode: \strval(...),
        );
    }

    public function encode(mixed $value): string
    {
        return ($this->encode)($value);
    }

    public function decode(string $value): mixed
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

    /**
     * @param non-empty-string $name
     * @param \Closure(ValueType): string $encode
     * @param \Closure(string): ValueType $decode
     */
    private function __construct(
        private string $name,
        private \Closure $encode,
        private \Closure $decode,
    ) {}
}
