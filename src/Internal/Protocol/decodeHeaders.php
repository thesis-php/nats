<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

use Thesis\Nats\Header\StatusCode;
use Thesis\Nats\Headers;
use Thesis\Nats\Status;

/**
 * @internal
 */
function decodeHeaders(string $encoded): Headers
{
    $prefix = 'NATS/1.0';

    if (!str_starts_with($encoded, $prefix)) {
        throw new \UnexpectedValueException(\sprintf('Invalid msg headers "%s" received: no leading prefix "%s".', $encoded, $prefix));
    }

    $headers = new Headers();

    $lines = explode("\r\n", trim($encoded));

    if (($status = parseStatus(array_shift($lines))) !== null) {
        $headers = $headers->with(StatusCode::Header, Status::tryFrom((int) $status) ?? Status::Unknown);
    }

    foreach ($lines as $line) {
        $keypair = explode(': ', $line);
        if (\count($keypair) !== 2) {
            throw new \InvalidArgumentException(\sprintf('Invalid msg header line "%s" received.', $line));
        }

        [$key, $value] = $keypair;

        if ($key !== '') {
            $headers = $headers->withAdded($key, $value);
        }
    }

    return $headers;
}

/**
 * @internal
 * @return ?numeric-string
 */
function parseStatus(string $line): ?string
{
    $chunks = explode(' ', $line);
    if (\count($chunks) === 2) {
        /** @var numeric-string */
        return $chunks[1];
    }

    return null;
}
