<?php

declare(strict_types=1);

namespace Thesis\Nats;

/**
 * @api
 * @template-implements \IteratorAggregate<non-empty-string, list<string>>
 */
final class Headers implements
    \IteratorAggregate,
    \Countable,
    \JsonSerializable
{
    /**
     * @param array<non-empty-string, list<string>> $values
     */
    public function __construct(
        private array $values = [],
    ) {}

    /**
     * @template T
     * @param HeaderKey<T> $key
     * @param T ...$values
     */
    public function with(HeaderKey $key, mixed ...$values): self
    {
        $headerKey = self::keyToString($key);

        $headers = clone $this;
        $headers->values[$headerKey] = array_values([
            ...$this->values[$headerKey] ?? [],
            ...array_map(
                static fn(mixed $value): string => $key->encode($value),
                $values,
            ),
        ]);

        return $headers;
    }

    public function without(HeaderKey $key): self
    {
        $headers = clone $this;
        unset($headers->values[self::keyToString($key)]);

        return $headers;
    }

    /**
     * @template T
     * @param HeaderKey<T> $key
     * @return ($key is OptionalHeaderKey<T> ? T : ?T) returns the first value associated with the given key
     */
    public function get(HeaderKey $key): mixed
    {
        $value = ($this->values[self::keyToString($key)] ?? [])[0] ?? null;

        if ($value === null && $key instanceof OptionalHeaderKey) {
            return $key->default($this);
        }

        if ($value !== null) {
            $value = $key->decode($value);
        }

        return $value;
    }

    /**
     * @template T
     * @param HeaderKey<T> $key
     * @return list<T> returns all values associated with the given key
     */
    public function values(HeaderKey $key): array
    {
        return array_map(
            static fn(string $value): mixed => $key->decode($value),
            $this->values[self::keyToString($key)] ?? [],
        );
    }

    public function exists(HeaderKey $key): bool
    {
        return isset($this->values[self::keyToString($key)]);
    }

    public function getIterator(): \Traversable
    {
        yield from $this->values;
    }

    public function count(): int
    {
        return \count($this->values);
    }

    /**
     * @return array<non-empty-string, list<string>>
     */
    public function jsonSerialize(): array
    {
        return $this->values;
    }

    /**
     * @return non-empty-string
     */
    private static function keyToString(mixed $key): string
    {
        if ($key instanceof \BackedEnum) {
            $key = (string) $key->value;
        } elseif ($key instanceof \Stringable) {
            $key = (string) $key;
        } else {
            throw new \InvalidArgumentException(
                \sprintf('The "%s" must be instance of \BackedEnum or implements \Stringable, but "%s" given.', HeaderKey::class, get_debug_type($key)),
            );
        }

        if ($key === '') {
            throw new \InvalidArgumentException('The header key cannot be empty.');
        }

        return $key;
    }
}
