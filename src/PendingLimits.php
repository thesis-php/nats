<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Thesis\Nats\Internal\Validation\Validate;

/**
 * Limits the messages a subscription has received, but its handler has not taken yet. The messages beyond the limits
 * are dropped and reported to {@see Client::onError()} as {@see Exception\SlowConsumer}, once until the handler catches up.
 * Only the payload is counted towards the bytes, as in nats.go.
 *
 * The limits are what bounds the memory a slow handler makes the pending messages take: the client never stops reading
 * the connection for it, so that the server keeps getting answered and the other subscriptions keep getting messages,
 * as in nats.go. With {@see unlimited()} nothing bounds that memory.
 *
 * The buffer size of {@see Client::subscribeIterator()} and {@see Client::requestMany()} is a different thing:
 * it is how many messages the iterator holds ahead of the consumer, they are not pending anymore. A message that does
 * not fit stays pending until the consumer takes one, so the limits are reached only behind a full buffer.
 *
 * @api
 */
final readonly class PendingLimits
{
    /** The same defaults as in nats.go. */
    public const int DEFAULT_MESSAGES = 512 * 1024;
    public const int DEFAULT_BYTES = 64 * 1024 * 1024;

    /**
     * @param ?positive-int $messages null for no limit
     * @param ?positive-int $bytes null for no limit
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public ?int $messages = self::DEFAULT_MESSAGES,
        public ?int $bytes = self::DEFAULT_BYTES,
    ) {
        if ($messages !== null) {
            Validate::count($messages, 'pending messages limit');
        }

        if ($bytes !== null) {
            Validate::count($bytes, 'pending bytes limit');
        }
    }

    public static function unlimited(): self
    {
        return new self(messages: null, bytes: null);
    }
}
