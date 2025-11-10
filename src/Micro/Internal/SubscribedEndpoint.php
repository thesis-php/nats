<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro\Internal;

use Thesis\Nats\Subscription;

/**
 * @internal
 */
final readonly class SubscribedEndpoint
{
    public function __construct(
        public EndpointHandler $endpoint,
        public Subscription $subscription,
    ) {}
}
