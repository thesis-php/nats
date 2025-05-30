<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * @api
 */
final class Subject
{
    private const string HEADER = 'Nats-Subject';

    public static function header(): ScalarKey
    {
        return ScalarKey::string(self::HEADER);
    }
}
