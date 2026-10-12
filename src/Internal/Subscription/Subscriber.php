<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Subscription;

use Thesis\Nats\Delivery;
use Thesis\Nats\Subscription;

/**
 * @internal
 */
final readonly class Subscriber
{
    /**
     * @param non-empty-string $subject
     * @param ?non-empty-string $queueGroup
     * @param \Closure(Delivery): bool $push returns false if the subscriber does not accept deliveries anymore
     * @param ?Subscription $subscription null for the internal subscriptions which are not exposed to the user
     */
    public function __construct(
        public string $subject,
        public ?string $queueGroup,
        public \Closure $push,
        public ?Subscription $subscription = null,
    ) {}
}
