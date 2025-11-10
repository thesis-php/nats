<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Subscription;

/**
 * @internal
 * @template-covariant T
 * @template-implements Operation<T>
 */
final readonly class Emit implements Operation
{
    /**
     * @param T $value
     */
    public function __construct(
        public mixed $value,
    ) {}
}
