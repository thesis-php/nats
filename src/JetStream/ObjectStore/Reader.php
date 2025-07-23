<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\ObjectStore;

/**
 * @api
 */
interface Reader
{
    public function eof(): bool;

    /**
     * @param positive-int $length
     * @return ?non-empty-string
     */
    public function read(int $length): ?string;
}
