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
     * @return ScalarKey<int>
     */
    public static function header(): ScalarKey
    {
        return ScalarKey::int(self::HEADER);
    }
}
