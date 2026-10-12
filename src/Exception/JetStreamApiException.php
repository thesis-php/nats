<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\NatsException;

/**
 * An error returned by the JetStream API. Errors without a dedicated subclass are thrown as {@see UnknownJetStreamApiError},
 * see https://github.com/nats-io/nats-server/blob/main/server/errors.json for the full list of codes.
 *
 * @api
 */
abstract class JetStreamApiException extends NatsException
{
    /**
     * @param int $code is the HTTP-like status code (400, 404, 503...), also available through {@see getCode()}
     * @param int $errCode is the JetStream specific error code (10059 for stream not found etc.)
     */
    public function __construct(
        int $code,
        public readonly int $errCode,
        public readonly string $description,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($description, $code, $previous);
    }
}
