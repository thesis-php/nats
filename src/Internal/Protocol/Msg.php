<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
final class Msg implements Frame
{
    /**
     * @param non-empty-string $subject subject name this message was received on
     * @param non-empty-string $sid the unique alphanumeric subscription ID of the subject
     * @param ?non-empty-string $replyTo the subject on which the publisher is listening for responses
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $sid,
        public readonly ?string $replyTo = null,
        public readonly Message $message = new Message(),
    ) {}

    public function encode(): string
    {
        $buffer = "MSG {$this->subject} {$this->sid}";

        if ($this->replyTo !== null) {
            $buffer .= " {$this->replyTo}";
        }

        return "{$buffer} {$this->message->encode()}";
    }
}
