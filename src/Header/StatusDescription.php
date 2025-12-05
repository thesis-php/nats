<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\Description;
use Thesis\Nats\Headers;
use Thesis\Nats\OptionalHeaderKey;

/**
 * @api
 * @template-implements OptionalHeaderKey<Description>
 */
enum StatusDescription: string implements OptionalHeaderKey
{
    case Header = 'Nats-Status-Description';

    public function encode(mixed $value): string
    {
        return $value->value;
    }

    public function decode(string $value): Description
    {
        return new Description($value === '' ? Description::OK : $value);
    }

    public function default(Headers $headers): Description
    {
        return new Description(Description::OK);
    }
}
