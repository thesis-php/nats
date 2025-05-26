<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * Contains the expected last sequence number on the subject and can be used to apply optimistic concurrency control at subject level.
 * Server will reject the message if it is not the public const string.
 *
 * @api
 */
enum ExpectedLastSubjSeq: string
{
    case Header = 'Nats-Expected-Last-Subject-Sequence';

    /**
     * @return Primitive<numeric-string>
     */
    public static function header(): Primitive
    {
        /** @var Primitive<numeric-string> */
        return new Primitive(self::Header);
    }
}
