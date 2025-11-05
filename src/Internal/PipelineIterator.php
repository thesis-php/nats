<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal;

use Amp\Cancellation;
use Amp\Pipeline;
use Revolt\EventLoop;
use Thesis\Nats\Iterator;

/**
 * @internal
 * @template-covariant T
 * @template-implements Iterator<T>
 */
final readonly class PipelineIterator implements Iterator
{
    /**
     * @template E
     * @param Pipeline\Queue<E> $queue
     * @param ?\Closure(?Cancellation=): void $unsubscribe
     * @return self<E>
     */
    public static function fromQueue(
        Pipeline\Queue $queue,
        ?\Closure $unsubscribe = null,
    ): self {
        return new self(
            pipeline: $queue->pipe(),
            queue: $queue,
            unsubscribe: $unsubscribe,
        );
    }

    /** @var \Closure(?Cancellation=): void */
    private \Closure $unsubscribe;

    /**
     * @param Pipeline\Pipeline<T> $pipeline
     * @param Pipeline\Queue<*> $queue
     * @param ?\Closure(?Cancellation=): void $unsubscribe
     */
    public function __construct(
        private Pipeline\Pipeline $pipeline,
        private Pipeline\Queue $queue,
        ?\Closure $unsubscribe = null,
    ) {
        $this->unsubscribe = $unsubscribe ?? static fn() => null;
    }

    public function complete(?Cancellation $cancellation = null): void
    {
        ($this->unsubscribe)($cancellation);

        if (!$this->queue->isComplete()) {
            $this->queue->complete();
        }
    }

    public function cancel(\Throwable $e, ?Cancellation $cancellation = null): void
    {
        ($this->unsubscribe)($cancellation);

        if (!$this->queue->isComplete()) {
            $this->queue->error($e);
        }
    }

    public function subscribe(callable $handler): callable
    {
        $iterator = $this->pipeline->getIterator();

        EventLoop::queue(static function () use ($iterator, $handler): void {
            foreach ($iterator as $value) {
                $handler($value);
            }
        });

        [$cancel, $complete] = [$this->cancel(...), $this->complete(...)];

        return static function (
            ?\Throwable $e = null,
            ?Cancellation $cancellation = null,
        ) use ($cancel, $complete): void {
            if ($e !== null) {
                $cancel($e, $cancellation);
            } else {
                $complete($cancellation);
            }
        };
    }

    public function filter(\Closure $filter): static
    {
        return new self(
            pipeline: $this->pipeline->filter($filter),
            queue: $this->queue,
            unsubscribe: $this->unsubscribe,
        );
    }

    public function map(\Closure $map): static
    {
        return new self(
            pipeline: $this->pipeline->map($map),
            queue: $this->queue,
            unsubscribe: $this->unsubscribe,
        );
    }

    /**
     * @template R
     * @param \Closure(T): Iterator\Outcome<R> $selector
     * @return static<R>
     */
    public function select(\Closure $selector): static
    {
        $complete = $this->complete(...);

        return new self(
            pipeline: $this->pipeline->flatMap(static function (mixed $value) use ($selector, $complete): array {
                $outcome = $selector($value);

                if ($outcome instanceof Iterator\Emit) {
                    /** @var array{R} */
                    return [$outcome->value];
                }

                if ($outcome instanceof Iterator\Complete) {
                    $complete();
                }

                return [];
            }),
            queue: $this->queue,
            unsubscribe: $this->unsubscribe,
        );
    }

    public function getIterator(): \Traversable
    {
        try {
            foreach ($this->pipeline as $value) {
                yield $value;
            }
        } finally {
            $this->complete();
        }
    }
}
