<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Hooks;

use Thesis\Nats\Internal\Protocol\Headers;

/**
 * @internal
 */
final readonly class MessageReceived
{
    /**
     * @param non-empty-string $subject
     * @param ?non-empty-string $replyTo
     * @param ?Headers<string> $headers
     */
    public function __construct(
        public string $subject,
        public string $sid,
        public ?string $replyTo = null,
        public ?string $payload = null,
        public ?Headers $headers = null,
    ) {}
}
