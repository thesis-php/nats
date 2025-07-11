<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
final readonly class Unsub implements Frame
{
    /**
     * @param non-empty-string $sid
     * @param ?positive-int $maxMessages
     */
    public function __construct(
        private string $sid,
        public ?int $maxMessages = null,
    ) {}

    public function encode(): string
    {
        $buffer = "UNSUB {$this->sid}";

        if ($this->maxMessages !== null) {
            $buffer .= " {$this->maxMessages}";
        }

        return "{$buffer}\r\n";
    }
}
