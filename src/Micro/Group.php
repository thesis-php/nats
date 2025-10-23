<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

use Amp\Cancellation;
use Thesis\Nats\NatsException;

/**
 * @api
 */
final class Group
{
    /**
     * @internal
     * @param non-empty-string $name
     * @param non-empty-string $queueGroup
     */
    public function __construct(
        private readonly Service $svc,
        private readonly string $name,
        private readonly string $queueGroup,
    ) {}


    /**
     * @param non-empty-string $name
     * @param callable(Request): void $handler
     * @throws NatsException
     */
    public function addEndpoint(
        string $name,
        callable $handler,
        EndpointConfig $config = new EndpointConfig(),
        ?Cancellation $cancellation = null,
    ): void {
        $this->svc->addEndpoint($this->name.'.'.$name, $handler, $config, $cancellation);
    }
}
