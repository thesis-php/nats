<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\JetStream\Api\StreamConfig;

/**
 * Used to specify a user-defined message ID.
 * It can be used e.g. for deduplication in conjunction with {@see StreamConfig::$duplicateWindow}.
 * Or to provide optimistic concurrency safety together with {@see ExpectedLastMsgID}.
 *
 * @api
 */
enum MsgId: string
{
    case Header = 'Nats-Msg-Id';

    /**
     * @return Primitive<non-empty-string>
     */
    public static function header(): Primitive
    {
        /** @var Primitive<non-empty-string> */
        return new Primitive(self::Header);
    }
}
