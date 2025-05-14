<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
enum DeliverPolicy: string
{
    case All = 'all';
    case Last = 'last';
    case New = 'new';
    case ByStartSequence = 'by_start_sequence';
    case ByStartTime = 'by_start_time';
    case LastPerSubject = 'last_per_subject';
}
