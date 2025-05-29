<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * Used to specify a reason for message deletion.
 *
 * @api
 */
final readonly class MarkerReason
{
    private const string HEADER = 'Nats-Marker-Reason';

    public static function header(): ScalarKey
    {
        return ScalarKey::string(self::HEADER);
    }
}
