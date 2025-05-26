<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal;

use Thesis\Nats\Message;

/**
 * @internal
 */
final class Command
{
    /**
     * @param non-empty-string $subject
     * @param non-empty-string $sid
     * @param ?non-empty-string $queueGroup
     */
    public static function sub(
        string $subject,
        string $sid,
        ?string $queueGroup = null,
    ): Protocol\Sub {
        return new Protocol\Sub(
            subject: $subject,
            sid: $sid,
            queueGroup: $queueGroup,
        );
    }

    /**
     * @param non-empty-string $sid
     * @param ?positive-int $maxMessages
     */
    public static function unsub(
        string $sid,
        ?int $maxMessages = null,
    ): Protocol\Unsub {
        return new Protocol\Unsub(
            sid: $sid,
            maxMessages: $maxMessages,
        );
    }

    /**
     * @param non-empty-string $subject
     * @param ?non-empty-string $replyTo
     */
    public static function pub(
        string $subject,
        Message $message,
        ?string $replyTo = null,
    ): Protocol\Pub {
        return new Protocol\Pub(
            subject: $subject,
            replyTo: $replyTo,
            message: self::createMessage($message),
        );
    }

    /**
     * @param non-empty-string $subject
     * @param non-empty-string $replyTo
     */
    public static function req(
        string $subject,
        string $replyTo,
        Message $message,
    ): Protocol\Pub {
        return self::pub($subject, $message, $replyTo);
    }

    private static function createMessage(Message $message): Protocol\Message
    {
        return new Protocol\Message(
            payload: $message->payload,
            headers: $message->headers ?? null,
        );
    }
}
