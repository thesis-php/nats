<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\NatsException;

/**
 * The TLS handshake with the server failed: e.g. its certificate is not trusted or does not match its name,
 * or the server requires a client certificate the client does not have.
 *
 * @api
 */
final class TlsHandshakeFailed extends NatsException {}
