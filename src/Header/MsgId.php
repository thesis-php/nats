<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\HeaderKey;
use Thesis\Nats\JetStream\Api\StreamConfig;

/**
 * Used to specify a user-defined message ID.
 * It can be used e.g. for deduplication in conjunction with {@see StreamConfig::$duplicateWindow}.
 * Or to provide optimistic concurrency safety together with {@see ExpectedLastMsgID}.
 *
 * @api
 * @template-implements HeaderKey<non-empty-string>
 */
enum MsgId: string implements HeaderKey
{
    case Header = 'Nats-Msg-Id';
}
