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
     * @param non-empty-string|HeaderKey<T> $key
     * @param ($key is HeaderKey<T> ? T : string) ...$values
     */
    public function with(string|HeaderKey $key, mixed ...$values): self
    {
        if ($key instanceof HeaderKey) {
            $values = array_map(
                /** @phpstan-ignore argument.type */
                static fn(mixed $value): string => $key->encode($value),
                $values,
            );
        }

        $headers = clone $this;
        $headers->values[self::keyToString($key)] = array_values($values);

        return $headers;
    }

    /**
     * @template T
     * @param non-empty-string|HeaderKey<T> $key
     * @param ($key is HeaderKey<T> ? T : string) ...$values
     */
    public function withAdded(string|HeaderKey $key, mixed ...$values): self
    {
        if ($key instanceof HeaderKey) {
            $values = array_map(
                /** @phpstan-ignore argument.type */
                static fn(mixed $value): string => $key->encode($value),
                $values,
            );
        }

        $headers = clone $this;
        $headers->values[$key = self::keyToString($key)] = [
            ...$this->values[$key] ?? [],
            ...array_values($values),
        ];

        return $headers;
    }

    /**
     * @param non-empty-string|HeaderKey<*> $key
     */
    public function without(string|HeaderKey $key): self
    {
        $headers = clone $this;
        unset($headers->values[self::keyToString($key)]);

        return $headers;
    }

    /**
     * @template T
     * @param non-empty-string|HeaderKey<T> $key
     * @return ($key is HeaderKey<*> ? ($key is OptionalHeaderKey<*> ? T : ?T) : ?string) returns the first value associated with the given key
     */
    public function get(string|HeaderKey $key): mixed
    {
        $value = $this->values[self::keyToString($key)] ?? [];

        if ($value === [] && $key instanceof OptionalHeaderKey) {
            return $key->default($this);
        }

        $value = $value[0] ?? null;

        if ($value !== null && $key instanceof HeaderKey) {
            $value = $key->decode($value);
        }

        return $value;
    }

    /**
     * @template T
     * @param non-empty-string|HeaderKey<T> $key
     * @return ($key is HeaderKey<*> ? list<T> : list<string>) returns all values associated with the given key
     */
    public function values(string|HeaderKey $key): array
    {
        $values = $this->values[self::keyToString($key)] ?? [];

        if ($key instanceof HeaderKey) {
            $values = array_map(
                static fn(string $value): mixed => $key->decode($value),
                $values,
            );
        }

        return $values;
    }

    /**
     * @param non-empty-string|HeaderKey<*> $key
     */
    public function exists(string|HeaderKey $key): bool
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
    private function keyToString(mixed $key): string
    {
        if ($key instanceof \BackedEnum) {
            $key = (string) $key->value;
        } elseif ($key instanceof \Stringable || \is_string($key)) {
            $key = (string) $key;
        } else {
            throw new \InvalidArgumentException(
                \sprintf('Header key must be string, instance of \Stringable or \BackedEnum, but "%s" given.', get_debug_type($key)),
            );
        }

        /** @var non-empty-string */
        return $key;
    }
}
