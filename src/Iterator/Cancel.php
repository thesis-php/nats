<?php

declare(strict_types=1);

namespace Thesis\Nats\Iterator;

/**
 * @api
 * @template-implements Decision<never>
 */
final readonly class Cancel implements Decision
{
    public function __construct(
        public \Throwable $e,
    ) {}
}
