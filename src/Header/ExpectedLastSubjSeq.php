<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * Contains the expected last sequence number on the subject and can be used to apply optimistic concurrency control at subject level.
 * Server will reject the message if it is not the public const string.
 *
 * @api
 */
final readonly class ExpectedLastSubjSeq
{
    private const string HEADER = 'Nats-Expected-Last-Subject-Sequence';

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
