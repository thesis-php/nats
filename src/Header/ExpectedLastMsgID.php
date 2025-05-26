<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\HeaderKey;

/**
 * Contains the expected last message ID on the subject and can be used to apply optimistic concurrency control at stream level.
 * Server will reject the message if it is not the public const string.
 *
 * @api
 * @template-implements HeaderKey<non-empty-string>
 */
enum ExpectedLastMsgID: string implements HeaderKey
{
    case Header = 'Nats-Expected-Last-Msg-Id';
}
