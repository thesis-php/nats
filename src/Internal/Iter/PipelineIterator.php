<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Iter;

use Amp\Cancellation;
use Amp\Future;
use Amp\Pipeline;
use Thesis\Nats\Iterator;
use Thesis\Nats\Subscription;
use function Amp\async;

/**
 * @internal
 * @template-covariant T
 * @template-implements Iterator<T>
 * @template-implements \IteratorAggregate<mixed, T>
 */
final class PipelineIterator implements Iterator, \IteratorAggregate
{
    /** @var ?Future<void> */
    private ?Future $complete = null;

    /**
     * @template E
     * @param Pipeline\Queue<E> $queue
     * @return self<E>
     */
    public static function fromQueue(
        Pipeline\Queue $queue,
        Subscription $subscription,
    ): self {
        return new self(
            pipeline: $queue->pipe(),
            queue: $queue,
            subscription: $subscription,
        );
    }

    /**
     * @param Pipeline\Pipeline<T> $pipeline
     * @param Pipeline\Queue<*> $queue
     */
    public function __construct(
        private readonly Pipeline\Pipeline $pipeline,
        private readonly Pipeline\Queue $queue,
        private readonly Subscription $subscription,
    ) {}

    public function stop(?Cancellation $cancellation = null): void
    {
        $this->doComplete($this->subscription->stop(...), $cancellation);
    }

    public function drain(?Cancellation $cancellation = null): void
    {
        $this->doComplete($this->subscription->drain(...), $cancellation);
    }

    public function filter(\Closure $filter): static
    {
        return new self(
            pipeline: $this->pipeline->filter($filter),
            queue: $this->queue,
            subscription: $this->subscription,
        );
    }

    public function map(\Closure $map): static
    {
        return new self(
            pipeline: $this->pipeline->map($map),
            queue: $this->queue,
            subscription: $this->subscription,
        );
    }

    public function getIterator(): \Traversable
    {
        return $this->pipeline->getIterator();
    }

    /**
     * @param \Closure(?Cancellation=): void $unsubscribe
     */
    private function doComplete(\Closure $unsubscribe, ?Cancellation $cancellation = null): void
    {
        $queue = $this->queue;
        $complete = static function () use (
            $queue,
            $unsubscribe,
            $cancellation,
        ): void {
            $unsubscribe($cancellation);

            if (!$queue->isComplete()) {
                $queue->complete();
            }
        };

        ($this->complete ??= async($complete))->await($cancellation);
    }
}
