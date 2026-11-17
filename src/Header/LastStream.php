<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * Sent with an idle heartbeat of a consumer: the stream sequence of the last message the consumer delivered.
 *
 * @api
 */
final readonly class LastStream
{
    private const string HEADER = 'Nats-Last-Stream';

    /**
     * @return ScalarKey<non-negative-int>
     */
    public static function header(): ScalarKey
    {
        /**
         * @var ScalarKey<non-negative-int>
         * @phpstan-ignore varTag.type
         */
        return ScalarKey::int(self::HEADER);
    }
}
