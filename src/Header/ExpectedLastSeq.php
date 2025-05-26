<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\HeaderKey;

/**
 * Contains the expected last sequence number of the stream and can be used to apply optimistic concurrency control at stream level.
 * Server will reject the message if it is not the public const string.
 *
 * @api
 * @template-implements HeaderKey<numeric-string>
 */
enum ExpectedLastSeq: string implements HeaderKey
{
    case Header = 'Nats-Expected-Last-Sequence';
}
