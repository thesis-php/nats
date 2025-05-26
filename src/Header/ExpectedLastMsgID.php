<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * Contains the expected last message ID on the subject and can be used to apply optimistic concurrency control at stream level.
 * Server will reject the message if it is not the public const string.
 *
 * @api
 */
enum ExpectedLastMsgID: string
{
    case Header = 'Nats-Expected-Last-Msg-Id';

    /**
     * @return Primitive<non-empty-string>
     */
    public static function header(): Primitive
    {
        /** @var Primitive<non-empty-string> */
        return new Primitive(self::Header);
    }
}
