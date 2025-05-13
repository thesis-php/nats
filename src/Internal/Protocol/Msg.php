<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
final readonly class Msg implements Frame
{
    /**
     * @param non-empty-string $subject subject name this message was received on
     * @param non-empty-string $sid the unique alphanumeric subscription ID of the subject
     * @param ?non-empty-string $replyTo the subject on which the publisher is listening for responses
     */
    public function __construct(
        public string $subject,
        public string $sid,
        public ?string $replyTo = null,
        public Message $message = new Message(),
    ) {}

    public function encode(): string
    {
        $op = 'MSG';
        if ($this->message->headers !== null) {
            $op = 'HMSG';
        }

        $buffer = "{$op} {$this->subject} {$this->sid}";

        if ($this->replyTo !== null) {
            $buffer .= " {$this->replyTo}";
        }

        return "{$buffer} {$this->message->encode()}";
    }
}
