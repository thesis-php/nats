<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
final class Message implements Frame
{
    /**
     * @param non-empty-string $subject subject name this message was received on
     * @param non-empty-string $sid the unique alphanumeric subscription ID of the subject
     * @param ?non-empty-string $replyTo the subject on which the publisher is listening for responses
     * @param ?non-empty-string $payload the message payload data
     * @param array<non-empty-string, non-empty-string> $headers header version NATS/1.0\r\n followed by one or more name: value pairs, each separated by \r\n
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $sid,
        public readonly ?string $replyTo = null,
        public readonly ?string $payload = null,
        public readonly array $headers = [],
    ) {}
}
