<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\NatsException;

/**
 * @api
 */
final class AuthorizationTimeout extends NatsException
{
    public function __construct(int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct('Authorization Timeout', $code, $previous);
    }
}
