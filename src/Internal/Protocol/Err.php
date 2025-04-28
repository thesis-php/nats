<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
final class Err implements Frame
{
    /** @var non-empty-string */
    public readonly string $message;

    /**
     * @param non-empty-string $message
     */
    public function __construct(
        string $message,
    ) {
        $this->message = trim($message, '\'') ?: 'unknown';
    }
}
