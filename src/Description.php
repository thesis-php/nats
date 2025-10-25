<?php

declare(strict_types=1);

namespace Thesis\Nats;

/**
 * @api
 */
enum Description: string
{
    case FlowControl = 'flowcontrol request';
    case IdleHeartbeat = 'idle heartbeat';
    case ConsumerDeleted = 'consumer deleted';
    case LeadershipChange = 'leadership change';
    case MaxBytesExceeded = 'message size exceeds maxbytes';
    case BatchCompleted = 'batch completed';
    case ServerShutdown = 'server shutdown';
    case Unknown = 'unknown';
}
