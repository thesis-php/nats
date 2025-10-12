<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * @api
 */
final readonly class ScheduleNext
{
    private const string HEADER = 'Nats-Schedule-Next';

    /**
     * @return ScalarKey<non-empty-string>
     */
    public static function header(): ScalarKey
    {
        return ScalarKey::nonEmptyString(self::HEADER);
    }
}
