<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\HeaderKey;

/**
 * Used to apply a purge of all prior messages in the stream ("all") or at the subject ("sub") before this message.
 *
 * @api
 * @template-implements HeaderKey<'all'|'sub'>
 */
enum MsgRollup: string implements HeaderKey
{
    case Header = 'Nats-Rollup';
}
