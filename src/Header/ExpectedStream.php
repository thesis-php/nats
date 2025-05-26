<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\HeaderKey;

/**
 * Contains stream name and is used to assure that the published message is received by expected stream.
 * Server will reject the message if it is not the public const string.
 *
 * @api
 * @template-implements HeaderKey<string>
 */
enum ExpectedStream: string implements HeaderKey
{
    case Header = 'Nats-Expected-Stream';
}
