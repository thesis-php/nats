<?php

declare(strict_types=1);

namespace Thesis\Nats\Header;

use Thesis\Nats\HeaderKey;

/**
 * Contains the expected last sequence number on the subject and can be used to apply optimistic concurrency control at subject level.
 * Server will reject the message if it is not the public const string.
 *
 * @api
 * @template-implements HeaderKey<numeric-string>
 */
enum ExpectedLastSubjSeq: string implements HeaderKey
{
    case Header = 'Nats-Expected-Last-Subject-Sequence';
}
