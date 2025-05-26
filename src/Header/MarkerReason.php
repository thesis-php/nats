<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\HeaderKey;

/**
 * Used to specify a reason for message deletion.
 *
 * @api
 * @template-implements HeaderKey<string>
 */
enum MarkerReason: string implements HeaderKey
{
    case Header = 'Nats-Marker-Reason';
}
