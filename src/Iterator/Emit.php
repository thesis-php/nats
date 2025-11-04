<?php

declare(strict_types=1);

namespace Thesis\Nats\Iterator;

/**
 * @api
 * @template T
 * @template-implements Decision<T>
 */
final readonly class Emit implements Decision
{
    /**
     * @param T $value
     */
    public function __construct(
        public mixed $value,
    ) {}
}
