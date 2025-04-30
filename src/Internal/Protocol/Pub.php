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
     */
    public function __construct(
        public readonly string $subject,
        public readonly ?string $replyTo = null,
        public readonly Message $message = new Message(),
    ) {}

    public function encode(): string
    {
        $op = 'PUB';
        if ($this->message->headers !== null) {
            $op = 'HPUB';
        }

        $buffer = "{$op} {$this->subject}";

        if ($this->replyTo !== null) {
            $buffer .= " {$this->replyTo}";
        }

        return "{$buffer} {$this->message->encode()}";
    }
}
