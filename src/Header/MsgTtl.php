<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\HeaderKey;

/**
 * Used to specify the TTL for a specific message. This will override the default TTL for the stream.
 *
 * @api
 * @template-implements HeaderKey<numeric-string>
 */
enum MsgTtl: string implements HeaderKey
{
    case Header = 'Nats-TTL';
}
