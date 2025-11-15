<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream;

use Amp\Cancellation;
use Amp\Pipeline;
use Thesis\Nats\Subscription;

/**
 * @api
 * @template-covariant T
 * @template-implements \IteratorAggregate<T>
 */
final readonly class DeliveryBatch implements \IteratorAggregate
{
    /**
     * @param Pipeline\Pipeline<T> $pipeline
     */
    public function __construct(
        private Pipeline\Pipeline $pipeline,
        private Subscription $subscription,
    ) {}

    /**
     * @param \Closure(T): bool $filter
     * @return self<T>
     */
    public function filter(\Closure $filter): self
    {
        return new self(
            $this->pipeline->filter($filter),
            $this->subscription,
        );
    }

    /**
     * @template E
     * @param \Closure(T): E $map
     * @return self<E>
     */
    public function map(\Closure $map): self
    {
        return new self(
            $this->pipeline->map($map),
            $this->subscription,
        );
    }

    public function stop(?Cancellation $cancellation = null): void
    {
        $this->subscription->stop($cancellation);
    }

    public function drain(?Cancellation $cancellation = null): void
    {
        $this->subscription->drain($cancellation);
    }

    public function getIterator(): \Traversable
    {
        return $this->pipeline->getIterator();
    }
}
