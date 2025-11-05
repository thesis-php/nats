<?php

declare(strict_types=1);

namespace Thesis\Nats\Iterator;

/**
 * @api
 * @template-implements Outcome<never>
 */
enum Discard implements Outcome
{
    case It;
}
