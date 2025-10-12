<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * @api
 */
final readonly class ScheduleTarget
{
    private const string HEADER = 'Nats-Schedule-Target';

    /**
     * @return ScalarKey<non-empty-string>
     */
    public static function header(): ScalarKey
    {
        return ScalarKey::nonEmptyString(self::HEADER);
    }
}
