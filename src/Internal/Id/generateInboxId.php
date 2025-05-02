<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Id;

/**
 * @internal
 * @param positive-int $length
 * @return non-empty-string
 */
function generateInboxId(int $length = 20): string
{
    $idx = generateUniqueId($length);

    return "_INBOX.{$idx}";
}
