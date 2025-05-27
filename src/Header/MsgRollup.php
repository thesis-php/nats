<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * Used to apply a purge of all prior messages in the stream ("all") or at the subject ("sub") before this message.
 *
 * @api
 */
final readonly class MsgRollup
{
    private const string HEADER = 'Nats-Rollup';

    /**
     * @return Value<'all'|'sub'>
     */
    public static function header(): Value
    {
        /** @var Value<'all'|'sub'> */
        return new Value(self::HEADER);
    }

    private function __construct() {}
}
