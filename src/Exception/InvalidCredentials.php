<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\NatsException;

/**
 * A credentials, JWT or NKey seed file cannot be read or holds no credentials.
 *
 * @api
 */
final class InvalidCredentials extends NatsException {}
