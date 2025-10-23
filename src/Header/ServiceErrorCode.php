<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * @api
 */
final class ServiceErrorCode
{
    private const string HEADER = 'Nats-Service-Error-Code';

    /**
     * @return ScalarKey<int>
     */
    public static function header(): ScalarKey
    {
        return ScalarKey::int(self::HEADER);
    }
}
