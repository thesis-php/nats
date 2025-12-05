<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * @api
 */
final readonly class PendingMessages
{
    private const string HEADER = 'Nats-Pending-Messages';

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
