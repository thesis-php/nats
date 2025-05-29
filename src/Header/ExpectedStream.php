<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

/**
 * Contains stream name and is used to assure that the published message is received by expected stream.
 * Server will reject the message if it is not the public const string.
 *
 * @api
 */
final readonly class ExpectedStream
{
    private const string HEADER = 'Nats-Expected-Stream';

    public static function header(): ScalarKey
    {
        return ScalarKey::string(self::HEADER);
    }
}
