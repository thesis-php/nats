<?php

declare(strict_types=1);

namespace Thesis\Nats\Exception;

/**
 * A JetStream API error without a dedicated exception, see {@see errCode} to tell it apart.
 *
 * @api
 */
final class UnknownJetStreamApiError extends JetStreamApiException {}
