<?php

declare(strict_types=1);

namespace Thesis\Nats\Iterator;

/**
 * @api
 * @template-implements Outcome<never>
 */
enum Complete implements Outcome
{
    case It;
}
