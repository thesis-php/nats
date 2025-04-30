<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\NatsException;

/**
 * @api
 */
final class MaximumControlLineExceeded extends \RuntimeException implements NatsException
{
    public function __construct(int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct('Maximum Control Line Exceeded', $code, $previous);
    }
}
