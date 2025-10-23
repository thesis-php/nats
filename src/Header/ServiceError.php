<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * @api
 */
final class ServiceError
{
    private const string HEADER = 'Nats-Service-Error';

    /**
     * @return ScalarKey<string>
     */
    public static function header(): ScalarKey
    {
        return ScalarKey::string(self::HEADER);
    }
}
