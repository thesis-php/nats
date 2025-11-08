<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use Thesis\Nats\Message;
use Thesis\Nats\NatsTestCase;

#[CoversClass(Service::class)]
final class ServiceTest extends NatsTestCase
{
    public function testServiceInfo(): void
    {
        $serviceConfig = new ServiceConfig(
            name: 'EchoService',
            version: '1.0.0',
            description: 'EchoService for testing',
            metadata: [
                'env' => 'test',
            ],
        );

        $srv = $this
            ->client()
            ->createService($serviceConfig);

        $info = $srv->info();

        self::assertSame($serviceConfig->name, $info->identity->name);
        self::assertSame($serviceConfig->version, $info->identity->version);
        self::assertSame($serviceConfig->metadata, $info->identity->metadata);
        self::assertCount(0, $info->endpoints);
        self::assertSame($serviceConfig->description, $info->description);

        $endpointConfig = new EndpointConfig('echo', metadata: ['v' => '1']);
        $srv->addEndpoint($endpointConfig, static fn() => null);

        $info = $srv->info();

        self::assertCount(1, $info->endpoints);
        self::assertSame($endpointConfig->name, $info->endpoints[0]->name);
        self::assertSame($endpointConfig->metadata, $info->endpoints[0]->metadata);
        self::assertSame('q', $info->endpoints[0]->queueGroup);

        $srv->stop();
    }

    public function testServiceStats(): void
    {
        $serviceConfig = new ServiceConfig(
            name: 'EchoService',
            version: '1.0.0',
        );

        $nc = $this->client();

        $srv = $nc->createService($serviceConfig);

        $srv->addEndpoint(new EndpointConfig('echo'), static function (Request $request): void {
            $request->respond(new Response($request->data));
        });

        $srv->addEndpoint(new EndpointConfig('error'), static function (Request $_): void {
            throw new \LogicException('Invalid request');
        });

        $stats = $srv->stats();

        self::assertTrue(new \DateTimeImmutable(timezone: new \DateTimeZone('UTC')) >= $stats->started);
        self::assertSame($serviceConfig->name, $stats->identity->name);
        self::assertSame($serviceConfig->version, $stats->identity->version);
        self::assertCount(2, $stats->endpoints);
        self::assertSame('echo', $stats->endpoints[0]->name);
        self::assertSame(0.0, $stats->endpoints[0]->processingTime);
        self::assertSame(0.0, $stats->endpoints[0]->averageProcessingTime);
        self::assertSame(0, $stats->endpoints[0]->numRequests);
        self::assertSame(0, $stats->endpoints[0]->numErrors);
        self::assertNull($stats->endpoints[0]->lastError);
        self::assertSame(0.0, $stats->endpoints[1]->processingTime);
        self::assertSame(0.0, $stats->endpoints[1]->averageProcessingTime);
        self::assertSame(0, $stats->endpoints[1]->numRequests);
        self::assertSame(0, $stats->endpoints[1]->numErrors);
        self::assertNull($stats->endpoints[1]->lastError);

        $response = $nc->request('echo', new Message('Ping'));
        self::assertSame('Ping', $response->message->payload);

        $deferred = new DeferredFuture();

        try {
            $nc->request('error', new Message('Ping'), new TimeoutCancellation(0.5));
        } catch (CancelledException) {
            $deferred->complete();
        }

        $deferred->getFuture()->await();

        $stats = $srv->stats();
        self::assertCount(2, $stats->endpoints);
        self::assertGreaterThan(1_000, $stats->endpoints[0]->processingTime);
        self::assertGreaterThan(1_000, $stats->endpoints[0]->averageProcessingTime);
        self::assertSame(1, $stats->endpoints[0]->numRequests);
        self::assertSame(0, $stats->endpoints[0]->numErrors);
        self::assertGreaterThan(1_000, $stats->endpoints[1]->processingTime);
        self::assertGreaterThan(1_000, $stats->endpoints[1]->averageProcessingTime);
        self::assertSame(1, $stats->endpoints[1]->numRequests);
        self::assertSame(1, $stats->endpoints[1]->numErrors);
        self::assertSame('Invalid request', $stats->endpoints[1]->lastError?->getMessage());

        $srv->stop();
    }

    public function testGroups(): void
    {
        $nc = $this->client();

        $srv = $nc->createService(new ServiceConfig(
            name: 'EchoService',
            version: '1.0.0',
        ));

        $srv
            ->addGroup(new GroupConfig('api'))
            ->addEndpoint(new EndpointConfig('echo'), static function (Request $request): void {
                $request->respond(new Response($request->data));
            });

        $response = $nc->request('api.echo', new Message('Ping'));
        self::assertSame('Ping', $response->message->payload);

        $srv->stop();
    }

    public function testNestedGroups(): void
    {
        $nc = $this->client();

        $srv = $nc->createService(new ServiceConfig(
            name: 'EchoService',
            version: '1.0.0',
        ));

        $srv
            ->addEndpoint(new EndpointConfig('metrics'), static function (Request $request): void {
                $request->respond(new Response('service metrics'));
            })
            ->addGroup(new GroupConfig('api'))
                ->addGroup(new GroupConfig('v1'))
                    ->addEndpoint(new EndpointConfig('echo'), static function (Request $request): void {
                        $request->respond(new Response($request->data));
                    });

        $response = $nc->request('api.v1.echo', new Message('Ping'));
        self::assertSame('Ping', $response->message->payload);

        $response = $nc->request('metrics');
        self::assertSame('service metrics', $response->message->payload);

        $srv->stop();
    }

    public function testSubjects(): void
    {
        $nc = $this->client();

        $srv = $nc->createService(new ServiceConfig(
            name: 'EchoService',
            version: '1.0.0',
        ));

        $srv
            ->addEndpoint(new EndpointConfig('Echo', subject: 'echo.*'), static function (Request $request): void {
                $request->respond(new Response($request->subject));
            });

        $response = $nc->request('echo.x');
        self::assertSame('echo.x', $response->message->payload);

        $srv->stop();
    }

    public function testQueueGroups(): void
    {
        $nc = $this->client();

        for ($i = 0; $i < 5; ++$i) {
            $srv = $nc->createService(new ServiceConfig(
                name: 'EchoService',
                version: '1.0.0',
                queueGroup: "q-{$i}",
            ));

            $srv
                ->addEndpoint(new EndpointConfig('svc.echo'), static function (Request $request) use ($i): void {
                    $request->respond(new Response("echo#{$i}"));
                });
        }

        $iterator = $nc->subscribeIterator('rply');
        $nc->publish('svc.echo', replyTo: 'rply');

        $replies = [];

        $count = 0;
        foreach ($iterator as $reply) {
            $replies[] = $reply->message->payload;

            if (++$count >= 5) {
                $iterator->stop();
            }
        }

        self::assertCount(5, $replies);
        self::assertEqualsCanonicalizing(['echo#0', 'echo#1', 'echo#2', 'echo#3', 'echo#4'], $replies);

        $srv->stop();
    }
}
