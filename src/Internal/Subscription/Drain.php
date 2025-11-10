<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Subscription;

/**
 * @internal
 * @template-implements Operation<never>
 */
enum Drain implements Operation
{
    case It;
}
