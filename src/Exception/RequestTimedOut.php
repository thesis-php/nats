<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\NatsException;

/**
 * @api
 */
final class RequestTimedOut extends NatsException
{
    /**
     * @param non-empty-string $subject
     */
    public static function after(string $subject, float $timeout, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('No reply to the request on "%s" in %ss.', $subject, $timeout), previous: $previous);
    }
}
