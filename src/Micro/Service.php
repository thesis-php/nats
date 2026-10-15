<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

use Amp\Cancellation;
use Amp\Future;
use Revolt\EventLoop;
use Thesis\Nats\Client;
use Thesis\Nats\Delivery;
use Thesis\Nats\Exception\ServiceAlreadyStopped;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\NatsException;
use Thesis\Nats\Subscription;
use function Amp\async;

/**
 * @api
 */
final class Service
{
    private const string DEFAULT_QUEUE_GROUP = 'q';

    private readonly \DateTimeImmutable $started;

    /** @var array<non-empty-string, Subscription> */
    private array $verbs = [];

    /** @var list<Internal\SubscribedEndpoint> */
    private array $endpoints = [];

    /** @var array<non-empty-string, Group> */
    private array $groups = [];

    /** @var non-empty-string */
    private readonly string $queue;

    /** @var ?Future<void> */
    private ?Future $shutdown = null;

    public private(set) Service\State $state = Service\State::Alive;

    public function __construct(
        private readonly Client $nc,
        private readonly ServiceIdentity $identity,
        private readonly ServiceConfig $config,
        private readonly Encoder $encoder,
    ) {
        $this->queue = $this->config->queueGroup ?? self::DEFAULT_QUEUE_GROUP;
        $this->started = new \DateTimeImmutable(
            timezone: new \DateTimeZone('UTC'),
        );

        $this->registerVerbHandler(Internal\Verb::Ping, $this->handlePing(...));
        $this->registerVerbHandler(Internal\Verb::Info, $this->handleInfo(...));
        $this->registerVerbHandler(Internal\Verb::Stats, $this->handleStats(...));
    }

    /**
     * @throws ServiceAlreadyStopped once the service has started stopping
     */
    public function addGroup(GroupConfig $config): Group
    {
        if ($this->stopping()) {
            throw new ServiceAlreadyStopped();
        }

        return $this->groups[$config->name] ??= new Group(
            svc: $this,
            name: $config->name,
            queueGroup: $config->queueGroup ?? $this->queue,
        );
    }

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
        if ($this->stopping()) {
            throw new ServiceAlreadyStopped();
        }

        $endpointHandler = new Internal\EndpointHandler(
            info: new EndpointInfo(
                name: $config->name,
                subject: $config->subject ?? $config->name,
                queueGroup: $config->queueGroup ?? $this->queue,
                metadata: $config->metadata,
            ),
            handler: $handler,
            encoder: $this->encoder,
            statsHandler: $this->config->statsHandler,
        );

        $subscription = $this->nc->subscribe(
            $endpointHandler->info->subject,
            $endpointHandler->handle(...),
            $endpointHandler->info->queueGroup,
            cancellation: $cancellation,
        );

        // The service has started stopping while subscribing: the stop no longer sees the endpoint, so it must not outlive the service.
        if ($this->stopping()) {
            $subscription->stop();

            throw new ServiceAlreadyStopped();
        }

        $this->endpoints[] = new Internal\SubscribedEndpoint($endpointHandler, $subscription);
        $this->supervise($subscription, $endpointHandler);

        return $this;
    }

    public function info(): Info
    {
        return new Info(
            identity: $this->identity,
            description: $this->config->description,
            endpoints: array_map(
                static fn(Internal\SubscribedEndpoint $it): EndpointInfo => $it->endpoint->info,
                $this->endpoints,
            ),
        );
    }

    public function stats(): Stats
    {
        return new Stats(
            identity: $this->identity,
            started: $this->started,
            endpoints: array_map(
                static fn(Internal\SubscribedEndpoint $it): EndpointStats => $it->endpoint->stats(),
                $this->endpoints,
            ),
        );
    }

    /**
     * Unsubscribes the endpoints at once, dropping the requests they have not handled yet.
     * The cancellation stops waiting only: the service is stopped to the end anyway.
     */
    public function stop(?Cancellation $cancellation = null): void
    {
        $this->shutdown(static fn(Subscription $subscription) => $subscription->stop(), $cancellation);
    }

    /**
     * Unsubscribes the endpoints and waits for them to handle the requests already received.
     * The cancellation stops waiting only: the service keeps draining and is stopped once the handlers are done.
     */
    public function drain(?Cancellation $cancellation = null): void
    {
        $this->shutdown(
            static function (Subscription $subscription): void {
                $subscription->drain();

                try {
                    $subscription->awaitCompletion();
                } catch (\Throwable) {
                    // The subscription has failed while draining, e.g. the connection is lost: the service is going down anyway.
                }
            },
            $cancellation,
        );
    }

    public function reset(): void
    {
        foreach ($this->endpoints as $it) {
            $it->endpoint->reset();
        }
    }

    /**
     * @param callable(Request): void $handler
     */
    private function registerVerbHandler(Internal\Verb $verb, callable $handler): void
    {
        $this->registerInternalHandler(new Internal\ControlSubject($verb), $verb->all(), $handler);
        $this->registerInternalHandler(new Internal\ControlSubject($verb, $this->config->name), $verb->kind(), $handler);
        $this->registerInternalHandler(new Internal\ControlSubject($verb, $this->config->name, $this->identity->id), $verb->value, $handler);
    }

    /**
     * @param non-empty-string $name
     * @param callable(Request): void $handler
     */
    private function registerInternalHandler(
        Internal\ControlSubject $subject,
        string $name,
        callable $handler,
    ): void {
        $encoder = $this->encoder;
        $this->verbs[$name] = $subscription = $this->nc->subscribe(
            $subject->value,
            static function (Delivery $delivery) use (
                $handler,
                $encoder,
            ): void {
                $handler(new Request($delivery, $encoder));
            },
        );

        $this->supervise($subscription);
    }

    private function handlePing(Request $request): void
    {
        $request->respondJson(new Internal\PingResponse($this->identity));
    }

    private function handleInfo(Request $request): void
    {
        $request->respondJson(new Internal\InfoResponse($this->info()));
    }

    private function handleStats(Request $request): void
    {
        $request->respondJson(new Internal\StatsResponse($this->stats()));
    }

    /**
     * Stops the service once a subscription of it completes from the outside: the client is stopped,
     * or the server rejected the subscription, which is reported to the error handler.
     */
    private function supervise(Subscription $subscription, ?Internal\EndpointHandler $endpoint = null): void
    {
        $future = async(function () use (
            $subscription,
            $endpoint,
        ): void {
            try {
                $subscription->awaitCompletion();
            } catch (\Throwable $e) {
                if (!$this->stopping()) {
                    $endpoint?->fail($e);
                    $this->reportError($e);
                }
            }

            if (!$this->stopping()) {
                $this->stop();
            }
        });
        $future->ignore();
    }

    private function stopping(): bool
    {
        return $this->state !== Service\State::Alive;
    }

    /**
     * @param \Closure(Subscription): void $complete
     */
    private function shutdown(\Closure $complete, ?Cancellation $cancellation = null): void
    {
        if ($this->shutdown === null) {
            $this->state = Service\State::Stopping;
            $this->shutdown = async($this->complete(...), $complete);
        }

        $this->shutdown->await($cancellation);
    }

    /**
     * @param \Closure(Subscription): void $complete
     */
    private function complete(\Closure $complete): void
    {
        try {
            // The endpoints go first, so that the service still answers PING, INFO and STATS while it drains.
            Future\await(array_map(
                static fn(Internal\SubscribedEndpoint $it): Future => async($complete, $it->subscription),
                $this->endpoints,
            ));

            Future\await(array_map(
                static fn(Subscription $it): Future => async($complete, $it),
                $this->verbs,
            ));
        } finally {
            $this->endpoints = [];
            $this->verbs = [];
            $this->groups = [];
            $this->state = Service\State::Stopped;

            if ($this->config->doneHandler !== null) {
                EventLoop::queue($this->callDoneHandler(...));
            }
        }
    }

    private function callDoneHandler(): void
    {
        try {
            if ($this->config->doneHandler !== null) {
                ($this->config->doneHandler)($this);
            }
        } catch (\Throwable $e) {
            if ($this->config->errorHandler === null) {
                throw $e;
            }

            $this->reportError($e);
        }
    }

    private function reportError(\Throwable $e): void
    {
        if ($this->config->errorHandler !== null) {
            EventLoop::queue($this->config->errorHandler, $this, $e);
        }
    }
}
