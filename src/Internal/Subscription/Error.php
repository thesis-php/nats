<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Subscription;

/**
 * @internal
 * @template-implements Operation<\Throwable>
 */
final readonly class Error implements Operation
{
    public function __construct(
        public \Throwable $exception,
    ) {}
}
