<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * Contains the expected last sequence number of the stream and can be used to apply optimistic concurrency control at stream level.
 * Server will reject the message if it is not the public const string.
 *
 * @api
 */
final readonly class ExpectedLastSeq
{
    private const string HEADER = 'Nats-Expected-Last-Sequence';

    /**
     * @return Value<numeric-string>
     */
    public static function header(): Value
    {
        /** @var Value<numeric-string> */
        return new Value(self::HEADER);
    }

    private function __construct() {}
}
