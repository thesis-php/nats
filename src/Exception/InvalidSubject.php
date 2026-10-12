<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\NatsException;

/**
 * Thrown before anything is sent when a subject or a queue group is malformed,
 * and for the server's "-ERR 'Invalid Subject'".
 *
 * @api
 */
final class InvalidSubject extends NatsException
{
    public function __construct(string $message = 'Invalid Subject', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public static function because(string $subject, string $reason): self
    {
        return new self("Invalid subject '{$subject}': {$reason}.");
    }

    public static function forQueueGroup(string $queueGroup, string $reason): self
    {
        return new self("Invalid queue group '{$queueGroup}': {$reason}.");
    }
}
