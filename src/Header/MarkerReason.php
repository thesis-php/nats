<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * Used to specify a reason for message deletion.
 *
 * @api
 */
enum MarkerReason: string
{
    case Header = 'Nats-Marker-Reason';

    public static function header(): Primitive
    {
        return new Primitive(self::Header);
    }
}
