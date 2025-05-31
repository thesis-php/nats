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
    public const string ROLLUP_SUBJECT = 'sub';
    public const string ROLLUP_ALL = 'all';

    /**
     * @return ScalarKey<self::ROLLUP_*>
     */
    public static function header(): ScalarKey
    {
        /** @var ScalarKey<self::ROLLUP_*> */
        return ScalarKey::nonEmptyString(self::HEADER);
    }
}
