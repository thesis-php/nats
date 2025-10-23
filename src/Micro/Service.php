<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

use Amp\Cancellation;
use Thesis\Nats\Client;
use Thesis\Nats\Delivery;
use Thesis\Nats\Json\Encoder;
use Thesis\Nats\NatsException;

/**
 * @api
 */
final class Service
{
    private const string DEFAULT_QUEUE_GROUP = 'q';

    private readonly \DateTimeImmutable $started;

    /** @var array<non-empty-string, non-empty-string> */
    private array $verbs = [];

    /** @var array<non-empty-string, Internal\EndpointHandler> */
    private array $endpoints = [];

    /** @var array<non-empty-string, Group> */
    private array $groups = [];

    /** @var non-empty-string */
    private readonly string $queue;

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

    public function addGroup(GroupConfig $config): Group
    {
        return $this->groups[$config->name] ??= new Group(
            svc: $this,
            name: $config->name,
            queueGroup: $config->queueGroup ?? $this->queue,
        );
    }

    /**
     * @param callable(Request): void $handler
     * @throws NatsException
     */
    public function addEndpoint(
        EndpointConfig $config,
        callable $handler,
        ?Cancellation $cancellation = null,
    ): self {
        $endpointHandler = new Internal\EndpointHandler(
            info: new EndpointInfo(
                name: $config->name,
                subject: $config->subject ?? $config->name,
                queueGroup: $config->queueGroup ?? $this->queue,
                metadata: $config->metadata,
            ),
            handler: $handler,
            encoder: $this->encoder,
        );

        $sid = $this->nc->subscribe(
            $endpointHandler->info->subject,
            $endpointHandler->handle(...),
            $endpointHandler->info->queueGroup,
            $cancellation,
        );

        $this->endpoints[$sid] = $endpointHandler;

        return $this;
    }

    public function info(): Info
    {
        return new Info(
            identity: $this->identity,
            description: $this->config->description,
            endpoints: array_map(
                static fn(Internal\EndpointHandler $endpoint): EndpointInfo => $endpoint->info,
                array_values($this->endpoints),
            ),
        );
    }

    public function stats(): Stats
    {
        return new Stats(
            identity: $this->identity,
            started: $this->started,
            endpoints: array_map(
                static fn(Internal\EndpointHandler $endpoint): EndpointStats => $endpoint->stats(),
                array_values($this->endpoints),
            ),
        );
    }

    public function stop(?Cancellation $cancellation = null): void
    {
        $this->stopEndpoints($cancellation);
        $this->stopInternalEndpoints($cancellation);
        $this->stopGroups();
    }

    public function reset(): void
    {
        foreach ($this->endpoints as $endpoint) {
            $endpoint->reset();
        }
    }

    public function __destruct()
    {
        if (\PHP_VERSION_ID >= 80400) {
            $this->stop();
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
        $this->verbs[$name] = $this->nc->subscribe(
            $subject->value,
            static function (Delivery $delivery) use ($handler, $encoder): void {
                $handler(new Request($delivery, $encoder));
            },
        );
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

    private function stopEndpoints(?Cancellation $cancellation = null): void
    {
        $endpoints = $this->endpoints;
        $this->endpoints = [];

        foreach (array_keys($endpoints) as $sid) {
            $this->nc->unsubscribe((string) $sid, $cancellation);
        }
    }

    private function stopInternalEndpoints(?Cancellation $cancellation = null): void
    {
        $verbs = $this->verbs;
        $this->verbs = [];

        foreach ($verbs as $sid) {
            $this->nc->unsubscribe($sid, $cancellation);
        }
    }

    private function stopGroups(): void
    {
        $this->groups = [];
    }
}
