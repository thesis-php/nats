<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
enum Proto: int
{
    case Original = 0;
    case Cluster = 1;
}
