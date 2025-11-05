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

    /**
     * @param \Closure(T): bool $filter
     * @return static<T>
     */
    public function filter(\Closure $filter): static;

    /**
     * @template R
     * @param \Closure(T): R $map
     * @return static<R>
     */
    public function map(\Closure $map): static;

    /**
     * @template R
     * @param \Closure(T): Iterator\Outcome<R> $filterMap
     * @return static<R>
     */
    public function filterMap(\Closure $filterMap): static;
}
