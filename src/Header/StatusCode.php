<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\Headers;
use Thesis\Nats\OptionalHeaderKey;
use Thesis\Nats\Status;

/**
 * The custom header of this library, under which the status code sent by the Nats server is stored.
 *
 * @api
 * @template-implements OptionalHeaderKey<Status>
 */
enum StatusCode: string implements OptionalHeaderKey
{
    case Header = 'Nats-Status-Code';

    public function encode(mixed $value): string
    {
        return (string) $value->value;
    }

    public function decode(string $value): Status
    {
        return Status::tryFrom((int) $value) ?? Status::Unknown;
    }

    public function default(Headers $headers): Status
    {
        return Status::OK;
    }
}
