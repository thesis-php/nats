<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

use Amp\Cancellation;
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
     * @throws NatsException
     */
    public function addEndpoint(
        EndpointConfig $config,
        callable $handler,
        ?Cancellation $cancellation = null,
    ): self {
        $this->svc->addEndpoint(
            config: new EndpointConfig(
                name: "{$this->name}.{$config->name}",
                subject: $config->subject,
                queueGroup: $config->queueGroup ?? $this->queueGroup,
                metadata: $config->metadata,
            ),
            handler: $handler,
            cancellation: $cancellation,
        );

        return $this;
    }

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
