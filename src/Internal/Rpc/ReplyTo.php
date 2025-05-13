<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Rpc;

use Thesis\Nats\Internal\Id;

/**
 * @internal
 */
final readonly class ReplyTo
{
    /**
     * @param non-empty-string $channel
     */
    public static function new(string $channel): self
    {
        $token = Id\generateUniqueId();

        return new self(
            subject: "{$channel}{$token}",
            token: $token,
        );
    }

    /**
     * @param non-empty-string $inboxId
     * @param non-empty-string $subject
     * @throws \InvalidArgumentException
     */
    public static function parse(string $inboxId, string $subject): self
    {
        $token = substr($subject, \strlen($inboxId)) ?: throw new \InvalidArgumentException("Invalid inbox id {$inboxId} received.");

        return new self(
            subject: $subject,
            token: $token,
        );
    }

    /**
     * @param non-empty-string $subject
     * @param non-empty-string $token
     */
    private function __construct(
        public string $subject,
        public string $token,
    ) {}
}
