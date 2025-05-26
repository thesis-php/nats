<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * Used to apply a purge of all prior messages in the stream ("all") or at the subject ("sub") before this message.
 *
 * @api
 */
enum MsgRollup: string
{
    case Header = 'Nats-Rollup';

    /**
     * @return Primitive<'all'|'sub'>
     */
    public static function header(): Primitive
    {
        /** @var Primitive<'all'|'sub'> */
        return new Primitive(self::Header);
    }
}
