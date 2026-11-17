<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

use Thesis\Nats\NatsException;

/**
 * The object store list stopped before every meta was received, e.g. the client was stopped
 * or the connection was lost and the metas sent meanwhile are gone.
 *
 * @api
 */
final class ObjectListIsIncomplete extends NatsException {}
