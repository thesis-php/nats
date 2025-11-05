<?php

declare(strict_types=1);

namespace Thesis\Nats\Iterator;

/**
 * @api
 * @template-covariant T
 * @template-implements Outcome<T>
 */
final readonly class Emit implements Outcome
{
    /**
     * @param T $value
     */
    public function __construct(
        public mixed $value,
    ) {}
}
