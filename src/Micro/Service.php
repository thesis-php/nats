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
        private readonly Config $config,
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
     * @param non-empty-string $name
     * @param ?non-empty-string $queueGroup
     */
    public function addGroup(
        string $name,
        ?string $queueGroup = null,
    ): Group {
        return $this->groups[$name] ??= new Group(
            svc: $this,
            name: $name,
            queueGroup: $queueGroup ?? $this->queue,
        );
    }

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
        $endpointHandler = new Internal\EndpointHandler(
            info: new EndpointInfo(
                name: $name,
                subject: $config->subject ?? $name,
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
    }

    public function stop(?Cancellation $cancellation = null): void
    {
        foreach (array_keys($this->endpoints) as $sid) {
            $this->nc->unsubscribe($sid, $cancellation);
        }

        foreach ($this->verbs as $sid) {
            $this->nc->unsubscribe($sid, $cancellation);
        }
    }

    public function reset(): void
    {
        foreach ($this->endpoints as $endpoint) {
            $endpoint->reset();
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
        $request->respondJson(new Internal\Ping($this->identity));
    }

    private function handleInfo(Request $request): void
    {
        $request->respondJson(new Internal\Info(
            identity: $this->identity,
            description: $this->config->description,
            endpoints: array_map(
                static fn (Internal\EndpointHandler $endpoint): EndpointInfo => $endpoint->info,
                $this->endpoints,
            ),
        ));
    }

    private function handleStats(Request $request): void
    {
        $request->respondJson(new Internal\Stats(
            identity: $this->identity,
            started: $this->started,
            endpoints: array_map(
                static fn (Internal\EndpointHandler $endpoint): EndpointStats => $endpoint->stats(),
                $this->endpoints,
            ),
        ));
    }
}
