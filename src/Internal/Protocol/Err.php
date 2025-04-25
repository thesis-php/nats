<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
final class Err
{
    /**
     * @param non-empty-string $message
     */
    public function __construct(
        public readonly string $message,
    ) {}
}
