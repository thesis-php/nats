<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * @api
 */
final class Sequence
{
    private const string HEADER = 'Nats-Sequence';

    /**
     * @return ScalarKey<non-negative-int>
     */
    public static function header(): ScalarKey
    {
        /** @var ScalarKey<non-negative-int> */
        return ScalarKey::int(self::HEADER);
    }
}
