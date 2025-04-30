<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
final class Message implements Frame
{
    /**
     * @param ?string $payload the message payload data
     * @param ?Headers $headers header version NATS/1.0␍␊ followed by one or more name: value pairs
     */
    public function __construct(
        public readonly ?string $payload = null,
        public readonly ?Headers $headers = null,
    ) {}

    public function encode(): string
    {
        $buffer = '';

        $length = \strlen($this->payload ?: '');

        $headers = $this->headers?->encode();
        $headersLength = \strlen($headers ?: '');

        $length += $headersLength;

        if ($headers !== null) {
            $buffer .= "{$headersLength} ";
        }

        $buffer .= "{$length}\r\n";

        if ($headers !== null) {
            $buffer .= "{$headers}";
        }

        return "{$buffer}{$this->payload}\r\n";
    }
}
