<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * @api
 */
final readonly class StatusDescription
{
    public const string HEADER = 'Nats-Status-Description';

    /**
     * @return ScalarKey<non-empty-string>
     */
    public static function header(): ScalarKey
    {
        return ScalarKey::string(self::HEADER);
    }
}
