<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * @api
 */
final class Stream
{
    private const string HEADER = 'Nats-Stream';

    /**
     * @return ScalarKey<non-empty-string>
     */
    public static function header(): ScalarKey
    {
        return ScalarKey::nonEmptyString(self::HEADER);
    }
}
