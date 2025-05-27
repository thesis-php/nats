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

    public static function header(): Value
    {
        return new Value(self::HEADER);
    }

    private function __construct() {}
}
