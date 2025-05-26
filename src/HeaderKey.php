<?php

declare(strict_types=1);

namespace Thesis\Nats;

/**
 * @api
 * @template ValueType = string
 */
interface HeaderKey
{
    /**
     * @param ValueType $value
     */
    public function encode(mixed $value): string;

    /**
     * @return ValueType
     */
    public function decode(string $value): mixed;
}
