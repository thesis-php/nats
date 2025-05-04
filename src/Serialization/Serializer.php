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
     * @param class-string<T> $class
     * @return T
     * @throws \Throwable
     */
    public function deserialize(string $class, string $data): object;
}
