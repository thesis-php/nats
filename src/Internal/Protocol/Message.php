<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

use Thesis\Nats\Headers;

/**
 * @internal
 */
final readonly class Message implements Frame
{
    /**
     * @param ?string $payload the message payload data
     * @param ?Headers $headers header version NATS/1.0␍␊ followed by one or more name: value pairs
     */
    public function __construct(
        public ?string $payload = null,
        public ?Headers $headers = null,
    ) {}

    /**
     * The size of the message the server checks against max_payload: the encoded headers and the payload, in bytes.
     *
     * @return non-negative-int
     */
    public function size(): int
    {
        return \strlen($this->payload ?? '') + ($this->headers !== null ? \strlen(encodeHeaders($this->headers)) : 0);
    }

    public function encode(): string
    {
        $buffer = '';

        $length = \strlen($this->payload ?? '');

        $headers = $this->headers !== null ? encodeHeaders($this->headers) : null;

        if ($headers !== null) {
            $headersLength = \strlen($headers);
            $length += $headersLength;
            $buffer .= "{$headersLength} ";
        }

        $buffer .= "{$length}\r\n";

        if ($headers !== null) {
            $buffer .= "{$headers}";
        }

        return "{$buffer}{$this->payload}\r\n";
    }
}
