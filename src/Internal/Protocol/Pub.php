<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
final class Pub implements Frame
{
    /**
     * @param non-empty-string $subject the destination subject to publish to
     * @param ?non-empty-string $replyTo the reply subject that subscribers can use to send a response back to the publisher/requestor
     * @param ?non-empty-string $payload the message payload data
     */
    public function __construct(
        public readonly string $subject,
        public readonly ?string $replyTo = null,
        public readonly ?string $payload = null,
    ) {}

    public function encode(): string
    {
        $buffer = "PUB {$this->subject}";

        if ($this->replyTo !== null) {
            $buffer .= " {$this->replyTo}";
        }

        $length = \strlen($this->payload ?: '');

        return "{$buffer} {$length}\r\n{$this->payload}\r\n";
    }
}
