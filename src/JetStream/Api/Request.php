<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 * @template-covariant ResponseType
 */
interface Request
{
    /**
     * @return non-empty-string
     */
    public function endpoint(): string;

    public function payload(): mixed;

    /**
     * @return non-empty-string
     */
    public function type(): string;
}
