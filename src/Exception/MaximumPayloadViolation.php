<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\NatsException;

/**
 * @api
 */
final class MaximumPayloadViolation extends NatsException
{
    /**
     * The server received a message larger than its max_payload and closed the connection.
     */
    public static function fromServer(): self
    {
        return new self('Maximum Payload Violation');
    }

    /**
     * The client refused to publish a message larger than the max_payload the server announced, nothing was sent.
     *
     * @param positive-int $size
     * @param positive-int $maxPayload
     */
    public static function forMessage(int $size, int $maxPayload): self
    {
        return new self("Maximum Payload Violation, the message of {$size} bytes exceeds the server limit of {$maxPayload} bytes");
    }

    private function __construct(string $message)
    {
        parent::__construct($message);
    }
}
