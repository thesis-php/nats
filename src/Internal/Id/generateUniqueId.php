<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Id;

/**
 * @param positive-int $length
 * @return non-empty-string
 */
function generateUniqueId(int $length = 20): string
{
    return bin2hex(random_bytes($length));
}
