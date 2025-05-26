<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Thesis\Nats\Header\StatusCode;

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
     * @template T of string
     * @param non-empty-string|HeaderKey<T> $key
     * @param ($key is HeaderKey<*> ? T : string) ...$value
     */
    public function with(string|HeaderKey $key, mixed ...$value): self
    {
        $headers = clone $this;
        $headers->values[self::keyToString($key)] = array_values($value);

        return $headers;
    }

    /**
     * @template T of string
     * @param non-empty-string|HeaderKey<T> $key
     * @param ($key is HeaderKey<*> ? T : string) ...$value
     */
    public function withAdded(string|HeaderKey $key, mixed ...$value): self
    {
        $headers = clone $this;
        $headers->values[$key = self::keyToString($key)] = [
            ...$this->values[$key] ?? [],
            ...array_values($value),
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
     * @template T of string
     * @param non-empty-string|HeaderKey<T> $key
     * @return ($key is HeaderKey<*> ? ($key is OptionalHeaderKey<*> ? T : ?T) : ?string) returns the first value associated with the given key
     */
    public function get(string|HeaderKey $key): ?string
    {
        $value = $this->values[self::keyToString($key)] ?? [];

        if ($value === [] && $key instanceof OptionalHeaderKey) {
            return $key->default($this);
        }

        return $value[0] ?? null;
    }

    /**
     * @template T of string
     * @param non-empty-string|HeaderKey<T> $key
     * @return ($key is HeaderKey<*> ? list<T> : list<string>) returns all values associated with the given key
     */
    public function values(string|HeaderKey $key): array
    {
        return $this->values[self::keyToString($key)] ?? [];
    }

    /**
     * @param non-empty-string|HeaderKey<*> $key
     */
    public function exists(string|HeaderKey $key): bool
    {
        return isset($this->values[self::keyToString($key)]);
    }

    public function status(): Status
    {
        return Status::tryFrom((int) $this->get(StatusCode::Header)) ?: Status::Unknown;
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
     * @param non-empty-string|HeaderKey<*> $key
     * @return non-empty-string
     */
    private function keyToString(string|HeaderKey $key): string
    {
        if ($key instanceof HeaderKey) {
            $key = (string) $key->value;
        }

        /** @var non-empty-string */
        return $key;
    }
}
