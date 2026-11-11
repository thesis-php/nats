<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\NatsException;

/**
 * The client requires TLS, but the server does not offer it.
 *
 * @api
 */
final class TlsIsNotAvailable extends NatsException {}
