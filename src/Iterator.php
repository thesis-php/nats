<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\Cancellation;

/**
 * @api
 * @template-covariant T
 * @template-extends \Traversable<mixed, T>
 */
interface Iterator extends \Traversable
{
    public function stop(?Cancellation $cancellation = null): void;

    public function drain(?Cancellation $cancellation = null): void;

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
}
