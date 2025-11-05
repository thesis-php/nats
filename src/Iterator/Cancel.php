<?php

declare(strict_types=1);

namespace Thesis\Nats\Iterator;

/**
 * @api
 * @template-implements Outcome<never>
 */
final readonly class Cancel implements Outcome
{
    public function __construct(
        public \Throwable $e,
    ) {}
}
