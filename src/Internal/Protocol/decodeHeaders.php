<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

use Thesis\Nats\Description;
use Thesis\Nats\Header\ScalarKey;
use Thesis\Nats\Header\StatusCode;
use Thesis\Nats\Header\StatusDescription;
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

    $status = parseStatus(array_shift($lines));
    if ($status !== null) {
        [$code, $description] = $status;

        $headers = $headers->with(StatusCode::Header, Status::tryFrom((int) $code) ?? Status::Unknown);

        if ($description !== '') {
            $headers = $headers->with(StatusDescription::Header, new Description(strtolower($description)));
        }
    }

    foreach ($lines as $line) {
        $keypair = explode(': ', $line);
        if (\count($keypair) !== 2) {
            throw new \InvalidArgumentException(\sprintf('Invalid msg header line "%s" received.', $line));
        }

        [$key, $value] = $keypair;

        if ($key !== '') {
            $headers = $headers->with(ScalarKey::string($key), $value);
        }
    }

    return $headers;
}

/**
 * @internal
 * @return ?array{numeric-string, string}
 */
function parseStatus(string $line): ?array
{
    $chunks = explode(' ', $line);
    if (\count($chunks) > 1) {
        /**
         * @var numeric-string $code
         */
        $code = $chunks[1];
        $description = implode(' ', \array_slice($chunks, 2));

        return [$code, $description];
    }

    return null;
}
