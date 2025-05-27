<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 * @template-covariant ResponseType of object
 */
interface Request
{
    /**
     * @return non-empty-string
     */
    public function endpoint(): string;

    public function payload(): mixed;

    /**
     * @return class-string<ResponseType>
     */
    public function type(): string;
}
