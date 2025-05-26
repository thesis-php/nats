<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * Contains the expected last sequence number of the stream and can be used to apply optimistic concurrency control at stream level.
 * Server will reject the message if it is not the public const string.
 *
 * @api
 */
enum ExpectedLastSeq: string
{
    case Header = 'Nats-Expected-Last-Sequence';

    /**
     * @return Primitive<numeric-string>
     */
    public static function header(): Primitive
    {
        /** @var Primitive<numeric-string> */
        return new Primitive(self::Header);
    }
}
