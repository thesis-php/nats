<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\Headers;
use Thesis\Nats\OptionalHeaderKey;

/**
 * The custom header of this library, under which the status code sent by the Nats server is stored.
 *
 * @api
 * @template-implements OptionalHeaderKey<numeric-string>
 */
enum StatusCode: string implements OptionalHeaderKey
{
    case Header = 'Nats-Status-Code';

    public function default(Headers $headers): string
    {
        return '200';
    }
}
