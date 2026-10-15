<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * Sent with an idle heartbeat of a push consumer that waits for the reply to its flow control request,
 * the value is the subject to reply to.
 *
 * @api
 */
final readonly class ConsumerStalled
{
    private const string HEADER = 'Nats-Consumer-Stalled';

    public static function header(): ScalarKey
    {
        return ScalarKey::string(self::HEADER);
    }
}
