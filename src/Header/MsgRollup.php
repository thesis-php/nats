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
     * @return ScalarKey<'all'|'sub'>
     */
    public static function header(): ScalarKey
    {
        /** @var ScalarKey<'all'|'sub'> */
        return ScalarKey::nonEmptyString(self::HEADER);
    }
}
