<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Rpc;

use Amp\DeferredFuture;
use Thesis\Nats\Delivery;

/**
 * @internal
 */
final readonly class PendingRequest
{
    /**
     * @param \Closure(Delivery): void $handle
     * @param DeferredFuture<Delivery> $deferred
     */
    public function __construct(
        public \Closure $handle,
        public DeferredFuture $deferred,
    ) {}

    public function __invoke(Delivery $delivery): void
    {
        ($this->handle)($delivery);
    }
}
