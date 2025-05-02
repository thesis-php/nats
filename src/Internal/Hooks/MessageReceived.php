<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Hooks;

use Thesis\Nats\Internal\Protocol\Headers;

/**
 * @internal
 */
final class MessageReceived
{
    /**
     * @param non-empty-string $subject
     * @param ?non-empty-string $replyTo
     * @param ?Headers<string> $headers
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $sid,
        public readonly ?string $replyTo = null,
        public readonly ?string $payload = null,
        public readonly ?Headers $headers = null,
    ) {}
}
