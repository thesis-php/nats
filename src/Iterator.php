<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\Cancellation;

/**
 * @api
 * @template-covariant T
 * @template-extends \IteratorAggregate<T>
 */
interface Iterator extends \IteratorAggregate
{
    public function complete(?Cancellation $cancellation = null): void;

    public function cancel(\Throwable $e, ?Cancellation $cancellation = null): void;

    /**
     * @param callable(T): void $handler
     * @return callable(?\Throwable=, ?Cancellation=): void callback to cancel subscription
     */
    public function subscribe(callable $handler): callable;
}
