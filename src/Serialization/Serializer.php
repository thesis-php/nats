<?php

declare(strict_types=1);

namespace Thesis\Nats\Serialization;

/**
 * @api
 */
interface Serializer
{
    /**
     * @template T of object
     * @param non-empty-string|class-string<T> $type
     * @param iterable<non-empty-string, mixed> $data
     * @return ($type is class-string<T> ? T : mixed)
     * @throws \Throwable
     */
    public function deserialize(string $type, iterable $data): mixed;
}
