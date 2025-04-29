<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
interface Frame
{
    /**
     * @return non-empty-string
     * @throws \Exception
     */
    public function encode(): string;
}
