<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * @api
 */
final class Subject
{
    private const string HEADER = 'Nats-Subject';

    /**
     * @return ScalarKey<non-empty-string>
     */
    public static function header(): ScalarKey
    {
        /** @var ScalarKey<non-empty-string> */
        return ScalarKey::string(self::HEADER);
    }
}
