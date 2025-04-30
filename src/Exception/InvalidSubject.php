<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\NatsException;

/**
 * @api
 */
final class InvalidSubject extends \RuntimeException implements NatsException
{
    public function __construct(int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct('Invalid Subject', $code, $previous);
    }
}
