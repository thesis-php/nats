<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

use Amp\Cancellation;
use Thesis\Nats\Client;
use Thesis\Nats\Delivery;
use Thesis\Nats\Json\Encoder;
use Thesis\Time\TimeSpan;
use function Amp\now;

/**
 * @api
 */
final class Service
{
    private readonly \DateTimeImmutable $started;

    /** @var array<non-empty-string, non-empty-string> */
    private array $verbs = [];

    /** @var list<Internal\Endpoint> */
    private array $endpoints = [];

    public function __construct(
        private readonly Client $nc,
        private readonly ServiceIdentity $identity,
        private readonly Config $config,
        private readonly Encoder $encoder,
    ) {
        $this->started = new \DateTimeImmutable(
            timezone: new \DateTimeZone('UTC'),
        );

        $this->registerVerbHandler(Internal\Verb::Ping, $this->handlePing(...));
        $this->registerVerbHandler(Internal\Verb::Info, $this->handleInfo(...));
        $this->registerVerbHandler(Internal\Verb::Stats, $this->handleStats(...));
    }

    /**
     * @param non-empty-string $name
     * @param callable(Request): void $handler
     */
    public function addEndpoint(
        string $name,
        callable $handler,
        EndpointConfig $config = new EndpointConfig(),
        ?Cancellation $cancellation = null,
    ): void {
        $info = new EndpointInfo(
            name: $name,
            subject: $config->subject ?? $name,
            queueGroup: $config->queueGroup,
            metadata: $config->metadata,
        );

        $stats = new EndpointStats(
            name: $info->name,
            subject: $info->subject,
            queueGroup: $info->queueGroup,
        );

        $sid = $this->nc->subscribe(
            $info->subject,
            $this->handleEndpointRequest($stats, $handler),
            $info->queueGroup,
            $cancellation,
        );

        $this->endpoints[] = new Internal\Endpoint(
            $info,
            $stats,
            $sid,
        );
    }

    public function stop(?Cancellation $cancellation = null): void
    {
        foreach ($this->verbs as $sid) {
            $this->nc->unsubscribe($sid, $cancellation);
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

    /**
     * @param callable(Request): void $handler
     * @return callable(Delivery): void
     */
    private function handleEndpointRequest(EndpointStats $stats, callable $handler): callable
    {
        return function (Delivery $delivery) use ($stats, $handler): void {
            $start = now();

            $request = new Request($delivery, $this->encoder);

            try {
                $handler($request);

                ++$stats->numRequests;
                $stats->processingTime = $stats->processingTime->add(TimeSpan::fromSeconds(now() - $start));
                $stats->averageProcessingTime = TimeSpan::fromNanoseconds($stats->processingTime->toNanoseconds() / $stats->numRequests);
            } catch (\Throwable $e) {
                ++$stats->numErrors;
                $stats->lastError = $e;
            }
        };
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
                static fn (Internal\Endpoint $endpoint): EndpointInfo => $endpoint->info,
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
                static fn (Internal\Endpoint $endpoint): EndpointStats => $endpoint->stats,
                $this->endpoints,
            ),
        ));
    }
}
