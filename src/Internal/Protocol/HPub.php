<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
final class HPub implements Frame
{
    /**
     * @param non-empty-string $subject the destination subject to publish to
     * @param ?non-empty-string $replyTo the reply subject that subscribers can use to send a response back to the publisher/requestor
     * @param ?non-empty-string $payload the message payload data
     * @param Headers $headers header version NATS/1.0␍␊ followed by one or more name: value pairs
     */
    public function __construct(
        public readonly string $subject,
        public readonly ?string $replyTo = null,
        public readonly ?string $payload = null,
        public readonly Headers $headers = new Headers(),
    ) {}

    public function encode(): string
    {
        $buffer = "HPUB {$this->subject}";

        if ($this->replyTo !== null) {
            $buffer .= " {$this->replyTo}";
        }

        $headers = $this->headers->encode();
        $headersLength = \strlen($headers);
        $length = $headersLength + \strlen($this->payload ?: '');

        return "{$buffer} {$headersLength} {$length}\r\n{$headers}{$this->payload}\r\n";
    }
}
