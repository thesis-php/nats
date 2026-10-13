<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Thesis\Nats\Internal\Validation\Validate;

/**
 * Limits the messages a subscription has received, but its handler has not taken yet. The messages beyond the limits
 * are dropped and reported to {@see Client::onError()} as {@see Exception\SlowConsumer}, once until the handler catches up.
 * Only the payload is counted towards the bytes, as in nats.go.
 *
 * Unlike these limits, the buffer size of {@see Client::subscribe()} does not drop anything: it is the capacity
 * of the queue in front of the handler, a message that does not fit waits until the handler takes one.
 * Such a waiting message is pending as well, so the limits are reached only behind a full buffer.
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
