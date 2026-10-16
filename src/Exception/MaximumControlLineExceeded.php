<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\NatsException;

/**
 * @api
 */
final class MaximumControlLineExceeded extends NatsException
{
    /**
     * @param non-empty-string $message the server's error, e.g. "maximum control line exceeded" with the details
     */
    public function __construct(string $message = 'Maximum Control Line Exceeded')
    {
        parent::__construct($message);
    }
}
