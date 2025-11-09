<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\Cancellation;
use Amp\Future;
use Thesis\Nats\Internal\Subscription\Drain;
use Thesis\Nats\Internal\Subscription\Operation;
use Thesis\Nats\Internal\Subscription\Stop;
use function Amp\async;

/**
 * @api
 */
final class Subscription
{
    /** @var ?Future<void> */
    private ?Future $done = null;

    /** @var list<callable(): void> */
    private array $onComplete = [];

    /**
     * @param Future<void> $completeMarker
     * @param \Closure(Operation<never>): void $complete
     */
    public function __construct(
        private readonly Future $completeMarker,
        private readonly \Closure $complete,
    ) {}

    /**
     * @param callable(): void $callback
     */
    public function onComplete(callable $callback): self
    {
        $this->onComplete[] = $callback;

        return $this;
    }

    public function stop(?Cancellation $cancellation = null): void
    {
        $this->complete(Stop::It, $cancellation);
    }

    public function drain(?Cancellation $cancellation = null): void
    {
        $this->complete(Drain::It, $cancellation);
    }

    public function suspend(?Cancellation $cancellation = null): void
    {
        $this->completeMarker->await($cancellation);
    }

    /**
     * @param Operation<never> $op
     */
    private function complete(Operation $op, ?Cancellation $cancellation = null): void
    {
        $onComplete = $this->onComplete;
        $this->onComplete = [];

        ($this->done ??= async($this->complete, $op))
            ->finally(static function () use ($onComplete): void {
                foreach ($onComplete as $callback) {
                    $callback();
                }
            })
            ->await($cancellation);
    }
}
