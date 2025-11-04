<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

use Thesis\Nats\Header\StatusCode;
use Thesis\Nats\Header\StatusDescription;
use Thesis\Nats\Headers;

/**
 * @internal
 * @return non-empty-string
 */
function encodeHeaders(Headers $headers): string
{
    $buffer = 'NATS/1.0';
    if ($headers->exists(StatusCode::Header)) {
        $status = $headers->get(StatusCode::Header)->value;
        $buffer .= " {$status}";
        $headers = $headers->without(StatusCode::Header);

        if ($headers->exists(StatusDescription::Header)) {
            $description = $headers->get(StatusDescription::Header);
            $buffer .= " {$description->value}";
            $headers = $headers->without(StatusDescription::Header);
        }
    }

    $buffer .= "\r\n";

    foreach ($headers as $headerKey => $headerValue) {
        foreach ($headerValue as $value) {
            $buffer .= "{$headerKey}: {$value}\r\n";
        }
    }

    return "{$buffer}\r\n";
}
