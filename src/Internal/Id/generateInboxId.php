<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Id;

/**
 * @internal
 * @param non-empty-string $prefix
 * @param positive-int $length
 * @return non-empty-string
 */
function generateInboxId(string $prefix = '_INBOX', int $length = 20): string
{
    $idx = generateUniqueId($length);

    return "{$prefix}.{$idx}";
}
