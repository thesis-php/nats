<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\NatsException;
use Thesis\Nats\PendingLimits;
use Thesis\Nats\Subscription;

/**
 * @api
 */
final class SlowConsumer extends NatsException
{
    /**
     * The server drops the messages the client does not read fast enough, then closes the connection.
     */
    public static function fromServer(): self
    {
        return new self('Slow Consumer');
    }

    /**
     * The subscription drops the messages beyond its pending limits, see {@see PendingLimits}.
     */
    public static function forSubscription(Subscription $subscription): self
    {
        return new self("Slow Consumer, messages dropped on \"{$subscription->subject}\"", $subscription);
    }

    /**
     * @param ?Subscription $subscription the subscription that drops the messages, null if reported by the server
     */
    private function __construct(
        string $message,
        public readonly ?Subscription $subscription = null,
    ) {
        parent::__construct($message);
    }
}
