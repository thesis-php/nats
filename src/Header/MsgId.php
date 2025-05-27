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
final readonly class MsgId
{
    private const string HEADER = 'Nats-Msg-Id';

    /**
     * @return Value<non-empty-string>
     */
    public static function header(): Value
    {
        /** @var Value<non-empty-string> */
        return new Value(self::HEADER);
    }

    private function __construct() {}
}
