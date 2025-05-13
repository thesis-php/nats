<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Api;

/**
 * @api
 */
enum RetentionPolicy: string
{
    case Limits = 'limits';
    case Interest = 'interest';
    case WorkQueue = 'workqueue';
}
