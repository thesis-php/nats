<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

use Amp\Cancellation;
use Thesis\Nats\Exception\ServiceAlreadyStopped;
use Thesis\Nats\NatsException;

/**
 * @api
 */
final readonly class Group
{
    /**
     * @internal
     * @param non-empty-string $name
     * @param non-empty-string $queueGroup
     */
    public function __construct(
        private Service $svc,
        private string $name,
        private string $queueGroup,
    ) {}

    /**
     * @param callable(Request): void $handler
     * @throws ServiceAlreadyStopped once the service has started stopping
     * @throws NatsException
     */
    public function addEndpoint(
        EndpointConfig $config,
        callable $handler,
        ?Cancellation $cancellation = null,
    ): self {
        $this->svc->addEndpoint(
            config: new EndpointConfig(
                name: $config->name,
                subject: "{$this->name}." . ($config->subject ?? $config->name),
                queueGroup: $config->queueGroup ?? $this->queueGroup,
                metadata: $config->metadata,
            ),
            handler: $handler,
            cancellation: $cancellation,
        );

        return $this;
    }

    /**
     * @throws ServiceAlreadyStopped once the service has started stopping
     */
    public function addGroup(GroupConfig $config): self
    {
        return $this->svc->addGroup(
            new GroupConfig(
                name: "{$this->name}.{$config->name}",
                queueGroup: $config->queueGroup ?? $this->queueGroup,
            ),
        );
    }
}
