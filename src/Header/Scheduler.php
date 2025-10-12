<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * @api
 */
final readonly class Scheduler
{
    private const string HEADER = 'Nats-Scheduler';

    /**
     * @return ScalarKey<non-empty-string>
     */
    public static function header(): ScalarKey
    {
        return ScalarKey::nonEmptyString(self::HEADER);
    }
}
