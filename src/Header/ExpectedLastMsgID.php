<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * Contains the expected last message ID on the subject and can be used to apply optimistic concurrency control at stream level.
 * Server will reject the message if it is not the public const string.
 *
 * @api
 */
final readonly class ExpectedLastMsgID
{
    private const string HEADER = 'Nats-Expected-Last-Msg-Id';

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
