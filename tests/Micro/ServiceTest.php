<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use Revolt\EventLoop;
use Thesis\Nats\Config;
use Thesis\Nats\Delivery;
use Thesis\Nats\Exception\PermissionViolated;
use Thesis\Nats\Exception\RequestHasNoResponders;
use Thesis\Nats\Exception\ServiceAlreadyStopped;
use Thesis\Nats\Header;
use Thesis\Nats\Headers;
use Thesis\Nats\Internal\Id;
use Thesis\Nats\Message;
use Thesis\Nats\NatsTestCase;
use function Amp\async;
use function Amp\delay;

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
        self::assertSame(0, $stats->endpoints[0]->processingTime);
        self::assertSame(0, $stats->endpoints[0]->averageProcessingTime);
        self::assertSame(0, $stats->endpoints[0]->numRequests);
        self::assertSame(0, $stats->endpoints[0]->numErrors);
        self::assertNull($stats->endpoints[0]->lastError);
        self::assertSame(0, $stats->endpoints[1]->processingTime);
        self::assertSame(0, $stats->endpoints[1]->averageProcessingTime);
        self::assertSame(0, $stats->endpoints[1]->numRequests);
        self::assertSame(0, $stats->endpoints[1]->numErrors);
        self::assertNull($stats->endpoints[1]->lastError);

        $response = $nc->request('echo', new Message('Ping'));
        self::assertSame('Ping', $response->message->payload);

        $response = $nc->request('error', new Message('Ping'), new TimeoutCancellation(5));
        self::assertSame(500, $response->message->headers?->get(Header\ServiceErrorCode::header()));
        self::assertSame('Invalid request', $response->message->headers->get(Header\ServiceError::header()));

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
        self::assertInstanceOf(ServiceError::class, $stats->endpoints[1]->lastError);
        self::assertSame(500, $stats->endpoints[1]->lastError->getCode());
        self::assertSame('Invalid request', $stats->endpoints[1]->lastError->description);
        self::assertInstanceOf(\LogicException::class, $stats->endpoints[1]->lastError->getPrevious());

        /** @var array{endpoints: list<array{last_error?: mixed}>} $raw */
        $raw = json_decode((string) $nc->request("\$SRV.STATS.EchoService.{$srv->info()->identity->id}")->message->payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('500:Invalid request', $raw['endpoints'][1]['last_error'] ?? null);

        $srv->stop();
    }

    public function testControlResponsesMatchMicroSchema(): void
    {
        $nc = $this->client();

        $srv = $nc->createService(new ServiceConfig(
            name: 'SchemaService',
            version: '1.0.0',
            description: 'Service for schema testing',
        ));

        $srv->addEndpoint(new EndpointConfig('echo', subject: 'schema.echo'), static function (Request $request): void {
            $request->respond(new Response($request->data));
        });

        $nc->request('schema.echo', new Message('Ping'));
        $nc->request('schema.echo', new Message('Ping'));

        /** @var array{description?: mixed} $info */
        $info = json_decode((string) $nc->request('$SRV.INFO.SchemaService')->message->payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Service for schema testing', $info['description'] ?? null);

        /** @var array{endpoints: list<array{processing_time: mixed, average_processing_time: mixed}>} $stats */
        $stats = json_decode((string) $nc->request('$SRV.STATS.SchemaService')->message->payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $stats['endpoints']);
        self::assertIsInt($stats['endpoints'][0]['processing_time']);
        self::assertIsInt($stats['endpoints'][0]['average_processing_time']);
        self::assertGreaterThan(0, $stats['endpoints'][0]['processing_time']);
        self::assertSame(intdiv($stats['endpoints'][0]['processing_time'], 2), $stats['endpoints'][0]['average_processing_time']);

        $srv->stop();
    }

    public function testEmptyMetadataIsEncodedAsJsonObject(): void
    {
        $nc = $this->client();

        $srv = $nc->createService(new ServiceConfig(
            name: 'NoMetadataService',
            version: '1.0.0',
        ));

        $srv->addEndpoint(new EndpointConfig('echo', subject: 'no-metadata.echo'), static function (Request $request): void {
            $request->respond(new Response($request->data));
        });

        foreach (['PING', 'INFO', 'STATS'] as $verb) {
            $payload = (string) $nc->request("\$SRV.{$verb}.NoMetadataService")->message->payload;

            self::assertStringContainsString('"metadata":{}', $payload, $verb);
            self::assertStringNotContainsString('"metadata":[]', $payload, $verb);
        }

        $srv->stop();
    }

    public function testMetadataIsEncodedAsJsonObject(): void
    {
        $nc = $this->client();

        $srv = $nc->createService(new ServiceConfig(
            name: 'MetadataService',
            version: '1.0.0',
            metadata: ['env' => 'test'],
        ));

        $srv->addEndpoint(new EndpointConfig('echo', subject: 'metadata.echo', metadata: ['v' => '1']), static function (Request $request): void {
            $request->respond(new Response($request->data));
        });

        $payload = (string) $nc->request('$SRV.INFO.MetadataService')->message->payload;

        self::assertStringContainsString('"metadata":{"env":"test"}', $payload);
        self::assertStringContainsString('"metadata":{"v":"1"}', $payload);

        $srv->stop();
    }

    public function testRequestError(): void
    {
        $nc = $this->client();
        $id = Id\generateUniqueId(10);

        $srv = $nc->createService(new ServiceConfig(
            name: 'ErrorService',
            version: '1.0.0',
        ));

        $srv->addEndpoint(new EndpointConfig('find', subject: "errors.{$id}"), static function (Request $request): void {
            $request->error(404, 'Not Found', '{"id":42}', new Headers()->with(Header\ServiceErrorCode::header(), 400));
        });

        $response = $nc->request("errors.{$id}", new Message('Ping'), new TimeoutCancellation(5));

        self::assertSame('{"id":42}', $response->message->payload);
        self::assertSame([404], $response->message->headers?->values(Header\ServiceErrorCode::header()));
        self::assertSame('Not Found', $response->message->headers->get(Header\ServiceError::header()));

        $stats = $srv->stats()->endpoints;
        self::assertCount(1, $stats);
        $stats = $stats[0];
        self::assertSame(1, $stats->numRequests);
        self::assertSame(1, $stats->numErrors);
        self::assertInstanceOf(ServiceError::class, $stats->lastError);
        self::assertSame(404, $stats->lastError->getCode());
        self::assertSame('Not Found', $stats->lastError->description);

        /** @var array{endpoints: list<array{last_error?: mixed}>} $raw */
        $raw = json_decode((string) $nc->request("\$SRV.STATS.ErrorService.{$srv->info()->identity->id}")->message->payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('404:Not Found', $raw['endpoints'][0]['last_error'] ?? null);

        $srv->stop();
    }

    public function testThrownServiceErrorIsReplied(): void
    {
        $nc = $this->client();
        $id = Id\generateUniqueId(10);

        $srv = $nc->createService(new ServiceConfig(
            name: 'ErrorService',
            version: '1.0.0',
        ));

        $srv->addEndpoint(new EndpointConfig('find', subject: "errors.{$id}"), static function (Request $_): void {
            throw new ServiceError(409, 'Conflict', 'details');
        });

        $response = $nc->request("errors.{$id}", new Message('Ping'), new TimeoutCancellation(5));

        self::assertSame('details', $response->message->payload);
        self::assertSame(409, $response->message->headers?->get(Header\ServiceErrorCode::header()));
        self::assertSame('Conflict', $response->message->headers->get(Header\ServiceError::header()));
        $stats = $srv->stats()->endpoints;
        self::assertCount(1, $stats);
        self::assertSame(1, $stats[0]->numErrors);

        $srv->stop();
    }

    public function testHandlerFailureAfterReplyIsCountedButNotRepliedAgain(): void
    {
        $nc = $this->client();
        $id = Id\generateUniqueId(10);

        $srv = $nc->createService(new ServiceConfig(
            name: 'ErrorService',
            version: '1.0.0',
        ));

        $srv->addEndpoint(new EndpointConfig('echo', subject: "errors.{$id}"), static function (Request $request): void {
            $request->respond(new Response($request->data));

            throw new \LogicException('Failed after reply');
        });

        /** @var \ArrayObject<int, Message> $replies */
        $replies = new \ArrayObject();
        $inbox = "_INBOX.{$id}";
        $nc->subscribe($inbox, static function (Delivery $delivery) use ($replies): void {
            $replies[] = $delivery->message;
        });
        $nc->publish("errors.{$id}", new Message('Ping'), replyTo: $inbox);

        delay(0.3);

        self::assertSame(['Ping'], array_map(static fn(Message $reply): ?string => $reply->payload, [...$replies]));
        self::assertFalse($replies[0]?->headers?->exists(Header\ServiceErrorCode::header()) ?? false);

        $stats = $srv->stats()->endpoints;
        self::assertCount(1, $stats);
        $stats = $stats[0];
        self::assertSame(1, $stats->numRequests);
        self::assertSame(1, $stats->numErrors);
        // No error reply has been sent, so the failure is counted as is, not as a 500 service error.
        self::assertInstanceOf(\LogicException::class, $stats->lastError);
        self::assertSame('Failed after reply', $stats->lastError->getMessage());

        $srv->stop();
    }

    public function testStatsHandler(): void
    {
        $nc = $this->client();
        $id = Id\generateUniqueId(10);

        $srv = $nc->createService(new ServiceConfig(
            name: 'StatsHandlerService',
            version: '1.0.0',
            statsHandler: static fn(EndpointInfo $info): array => ['endpoint' => $info->name, 'subject' => $info->subject],
        ));

        $srv->addEndpoint(new EndpointConfig('echo', subject: "stats.{$id}"), static function (Request $request): void {
            $request->respond(new Response($request->data));
        });

        $stats = $srv->stats()->endpoints;
        self::assertCount(1, $stats);
        self::assertSame(['endpoint' => 'echo', 'subject' => "stats.{$id}"], $stats[0]->data);

        $payload = (string) $nc->request("\$SRV.STATS.StatsHandlerService.{$srv->info()->identity->id}")->message->payload;
        self::assertStringContainsString("\"data\":{\"endpoint\":\"echo\",\"subject\":\"stats.{$id}\"}", $payload);

        $srv->stop();
    }

    public function testStatsWithoutStatsHandlerHaveNoData(): void
    {
        $nc = $this->client();

        $srv = $nc->createService(new ServiceConfig(
            name: 'NoStatsHandlerService',
            version: '1.0.0',
        ));

        $srv->addEndpoint(new EndpointConfig('echo', subject: 'no-stats.' . Id\generateUniqueId(10)), static fn() => null);

        $stats = $srv->stats()->endpoints;
        self::assertCount(1, $stats);
        self::assertNull($stats[0]->data);

        $payload = (string) $nc->request("\$SRV.STATS.NoStatsHandlerService.{$srv->info()->identity->id}")->message->payload;
        self::assertStringNotContainsString('"data"', $payload);

        $srv->stop();
    }

    public function testDrainHandlesReceivedRequests(): void
    {
        $nc = $this->client();
        $id = Id\generateUniqueId(10);

        /** @var DeferredFuture<void> $started */
        $started = new DeferredFuture();

        /** @var DeferredFuture<void> $release */
        $release = new DeferredFuture();

        /** @var \ArrayObject<int, Service> $done */
        $done = new \ArrayObject();

        $srv = $nc->createService(new ServiceConfig(
            name: 'DrainService',
            version: '1.0.0',
            doneHandler: static function (Service $srv) use ($done): void {
                $done[] = $srv;
            },
        ));

        $srv->addEndpoint(new EndpointConfig('slow', subject: "drain.{$id}"), static function (Request $request) use (
            $started,
            $release,
        ): void {
            if (!$started->isComplete()) {
                $started->complete();
            }

            $release->getFuture()->await();
            $request->respond(new Response("done {$request->data}"));
        });

        $replies = [];
        for ($i = 0; $i < 3; ++$i) {
            $replies[] = async($nc->request(...), "drain.{$id}", new Message((string) $i), cancellation: new TimeoutCancellation(5));
        }

        $started->getFuture()->await(new TimeoutCancellation(5));

        // Let the other requests reach the endpoint's buffer.
        delay(0.2);

        $drain = async($srv->drain(...));
        delay(0.1);
        self::assertFalse($drain->isComplete());
        self::assertSame(Service\State::Stopping, $srv->state);

        $release->complete();
        $drain->await(new TimeoutCancellation(5));

        self::assertSame(Service\State::Stopped, $srv->state);
        self::assertSame(
            ['done 0', 'done 1', 'done 2'],
            array_map(static fn(Delivery $delivery): ?string => $delivery->message->payload, Future\await($replies)),
        );

        delay(0.05);
        self::assertCount(1, $done);
        self::assertSame($srv, $done[0]);
    }

    public function testCancelledDrainStillStopsTheService(): void
    {
        $nc = $this->client();
        $id = Id\generateUniqueId(10);

        /** @var DeferredFuture<void> $started */
        $started = new DeferredFuture();

        /** @var DeferredFuture<void> $release */
        $release = new DeferredFuture();

        /** @var DeferredFuture<Service> $done */
        $done = new DeferredFuture();

        $srv = $nc->createService(new ServiceConfig(
            name: 'CancelledDrainService',
            version: '1.0.0',
            doneHandler: $done->complete(...),
        ));

        $srv->addEndpoint(new EndpointConfig('slow', subject: "cancelled-drain.{$id}"), static function (Request $request) use (
            $started,
            $release,
        ): void {
            $started->complete();
            $release->getFuture()->await();
            $request->respond(new Response('done'));
        });

        $reply = async($nc->request(...), "cancelled-drain.{$id}", cancellation: new TimeoutCancellation(5));
        $started->getFuture()->await(new TimeoutCancellation(5));

        try {
            $srv->drain(new TimeoutCancellation(0.2));
            self::fail('The handler is blocked, the drain must be cancelled.');
        } catch (CancelledException) {
        }

        // Only the wait is cancelled: the service is still draining.
        self::assertSame(Service\State::Stopping, $srv->state);
        self::assertFalse($done->getFuture()->isComplete());

        $release->complete();

        self::assertSame('done', $reply->await()->message->payload);
        self::assertSame($srv, $done->getFuture()->await(new TimeoutCancellation(5)));
        self::assertSame(Service\State::Stopped, $srv->state);

        foreach (["cancelled-drain.{$id}", "\$SRV.PING.CancelledDrainService.{$srv->info()->identity->id}"] as $subject) {
            try {
                $nc->request($subject, cancellation: new TimeoutCancellation(5));
                self::fail("Nothing must be subscribed to {$subject} after the service has stopped.");
            } catch (RequestHasNoResponders) {
            }
        }
    }

    public function testUnencodableJsonResponseIsRepliedWithError(): void
    {
        $nc = $this->client();
        $id = Id\generateUniqueId(10);

        $srv = $nc->createService(new ServiceConfig(
            name: 'JsonErrorService',
            version: '1.0.0',
        ));

        $srv->addEndpoint(new EndpointConfig('nan', subject: "json-error.{$id}"), static function (Request $request): void {
            $request->respondJson(NAN);
        });

        $response = $nc->request("json-error.{$id}", cancellation: new TimeoutCancellation(5));

        self::assertSame(500, $response->message->headers?->get(Header\ServiceErrorCode::header()));

        $stats = $srv->stats()->endpoints;
        self::assertCount(1, $stats);
        self::assertSame(1, $stats[0]->numErrors);
        self::assertInstanceOf(\JsonException::class, $stats[0]->lastError?->getPrevious());

        $srv->stop();
    }

    public function testEndpointsCannotBeAddedOnceStopping(): void
    {
        $nc = $this->client();
        $id = Id\generateUniqueId(10);

        /** @var DeferredFuture<void> $started */
        $started = new DeferredFuture();

        /** @var DeferredFuture<void> $release */
        $release = new DeferredFuture();

        $srv = $nc->createService(new ServiceConfig(
            name: 'StoppingService',
            version: '1.0.0',
        ));

        $group = $srv->addGroup(new GroupConfig("stopping.{$id}"));

        $srv->addEndpoint(new EndpointConfig('slow', subject: "stopping.{$id}.slow"), static function (Request $request) use (
            $started,
            $release,
        ): void {
            $started->complete();
            $release->getFuture()->await();
            $request->respond(new Response('done'));
        });

        $reply = async($nc->request(...), "stopping.{$id}.slow", cancellation: new TimeoutCancellation(5));
        $started->getFuture()->await(new TimeoutCancellation(5));

        $drain = async($srv->drain(...));
        delay(0.1);
        self::assertSame(Service\State::Stopping, $srv->state);

        $echo = static function (Request $request): void {
            $request->respond(new Response($request->data));
        };

        $adds = [
            'service while draining' => static fn() => $srv->addEndpoint(new EndpointConfig('late', subject: "stopping.{$id}.late"), $echo),
            'group while draining' => static fn() => $group->addEndpoint(new EndpointConfig('grouped'), $echo),
            'new group while draining' => static fn() => $srv->addGroup(new GroupConfig('late')),
            'nested group while draining' => static fn() => $group->addGroup(new GroupConfig('nested')),
        ];

        foreach ($adds as $case => $add) {
            try {
                $add();
                self::fail("An endpoint must not be added to a stopping service: {$case}.");
            } catch (ServiceAlreadyStopped) {
            }
        }

        $release->complete();
        self::assertSame('done', $reply->await()->message->payload);
        $drain->await(new TimeoutCancellation(5));
        self::assertSame(Service\State::Stopped, $srv->state);

        try {
            $srv->addEndpoint(new EndpointConfig('after', subject: "stopping.{$id}.after"), $echo);
            self::fail('An endpoint must not be added to a stopped service.');
        } catch (ServiceAlreadyStopped) {
        }

        foreach (["stopping.{$id}.late", "stopping.{$id}.grouped", "stopping.{$id}.after"] as $subject) {
            try {
                $nc->request($subject, cancellation: new TimeoutCancellation(5));
                self::fail("Nothing must be subscribed to {$subject}.");
            } catch (RequestHasNoResponders) {
            }
        }

        self::assertSame([], $srv->info()->endpoints);
    }

    public function testDoneHandlerIsCalledOnce(): void
    {
        /** @var \ArrayObject<int, Service> $done */
        $done = new \ArrayObject();

        $srv = $this->client()->createService(new ServiceConfig(
            name: 'DoneService',
            version: '1.0.0',
            doneHandler: static function (Service $srv) use ($done): void {
                $done[] = $srv;
            },
        ));

        $srv->addEndpoint(new EndpointConfig('echo', subject: 'done.' . Id\generateUniqueId(10)), static fn() => null);

        self::assertSame(Service\State::Alive, $srv->state);

        $srv->stop();
        $srv->stop();
        $srv->drain();

        delay(0.05);

        self::assertSame(Service\State::Stopped, $srv->state);
        self::assertCount(1, $done);
        self::assertSame($srv, $done[0]);
        self::assertSame([], $srv->info()->endpoints);
    }

    public function testDoneHandlerFailureIsReportedAfterShutdown(): void
    {
        /** @var DeferredFuture<array{Service, \Throwable}> $reported */
        $reported = new DeferredFuture();
        $failure = new \RuntimeException('Done handler failed');
        $nc = $this->client();

        $srv = $nc->createService(new ServiceConfig(
            name: 'DoneFailureService',
            version: '1.0.0',
            doneHandler: static function (Service $srv) use ($failure): void {
                $srv->stop();

                throw $failure;
            },
            errorHandler: static function (Service $srv, \Throwable $e) use ($reported): void {
                $srv->drain();
                $reported->complete([$srv, $e]);
            },
        ));

        $subject = 'done-failure.' . Id\generateUniqueId(10);
        $srv->addEndpoint(new EndpointConfig('echo', subject: $subject), static fn() => null);
        $srv->stop();

        self::assertSame([$srv, $failure], $reported->getFuture()->await(new TimeoutCancellation(5)));
        self::assertSame(Service\State::Stopped, $srv->state);
        self::assertSame([], $srv->info()->endpoints);

        $this->expectException(RequestHasNoResponders::class);
        $nc->request($subject, cancellation: new TimeoutCancellation(5));
    }

    public function testCallbackFailuresReachEventLoopErrorHandler(): void
    {
        $nc = $this->client();
        $previousHandler = EventLoop::getErrorHandler();

        try {
            foreach ([false, true] as $withErrorHandler) {
                /** @var DeferredFuture<\Throwable> $reported */
                $reported = new DeferredFuture();
                EventLoop::setErrorHandler($reported->complete(...));

                $doneFailure = new \RuntimeException('Done handler failed');
                $errorFailure = new \RuntimeException('Error handler failed');
                $srv = $nc->createService(new ServiceConfig(
                    name: 'CallbackFailureService',
                    version: '1.0.0',
                    doneHandler: static function (Service $_) use ($doneFailure): void {
                        throw $doneFailure;
                    },
                    errorHandler: $withErrorHandler ? static function (Service $_, \Throwable $e) use ($doneFailure, $errorFailure): void {
                        self::assertSame($doneFailure, $e);

                        throw $errorFailure;
                    } : null,
                ));

                $srv->addEndpoint(new EndpointConfig('echo', subject: 'callback-failure.' . Id\generateUniqueId(10)), static fn() => null);
                $srv->stop();

                self::assertSame(
                    $withErrorHandler ? $errorFailure : $doneFailure,
                    $reported->getFuture()->await(new TimeoutCancellation(5)),
                );
                self::assertSame(Service\State::Stopped, $srv->state);
            }
        } finally {
            EventLoop::setErrorHandler($previousHandler);
        }
    }

    public function testServiceStopsWithClient(): void
    {
        /** @var DeferredFuture<Service> $done */
        $done = new DeferredFuture();
        /** @var \ArrayObject<int, \Throwable> $errors */
        $errors = new \ArrayObject();

        $nc = $this->client();

        $srv = $nc->createService(new ServiceConfig(
            name: 'ClientStopService',
            version: '1.0.0',
            doneHandler: $done->complete(...),
            errorHandler: static function (Service $_, \Throwable $e) use ($errors): void {
                $errors[] = $e;
            },
        ));

        $srv->addEndpoint(new EndpointConfig('echo', subject: 'client-stop.' . Id\generateUniqueId(10)), static fn() => null);

        $nc->stop();

        self::assertSame($srv, $done->getFuture()->await(new TimeoutCancellation(5)));
        self::assertSame(Service\State::Stopped, $srv->state);
        self::assertCount(0, $errors);
    }

    public function testErrorHandlerIsCalledWhenSubscriptionFails(): void
    {
        $nc = $this->clientWithConfig(new Config(
            urls: [$this->natsAddress()],
            user: 'restricted',
            password: 'Pswd1',
        ));

        /** @var DeferredFuture<Service> $done */
        $done = new DeferredFuture();
        /** @var \ArrayObject<int, \Throwable> $errors */
        $errors = new \ArrayObject();

        // The user may subscribe to allowed.> only: the $SRV control subjects are rejected by the server.
        $srv = $nc->createService(new ServiceConfig(
            name: 'ForbiddenService',
            version: '1.0.0',
            doneHandler: $done->complete(...),
            errorHandler: static function (Service $_, \Throwable $e) use ($errors): void {
                $errors[] = $e;
            },
        ));

        $srv->addEndpoint(new EndpointConfig('echo', subject: 'allowed.' . Id\generateUniqueId(10)), static fn() => null);

        self::assertSame($srv, $done->getFuture()->await(new TimeoutCancellation(5)));
        self::assertSame(Service\State::Stopped, $srv->state);

        // All the $SRV subscriptions are rejected, but only the first failure is reported: the service is stopping then.
        delay(0.05);
        self::assertCount(1, $errors);
        self::assertInstanceOf(PermissionViolated::class, $errors[0]);
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

            $srv->addEndpoint(new EndpointConfig('echo', subject: 'svc.echo'), static function (Request $request) use ($i): void {
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

        $srv->stop(); // @phpstan-ignore variable.undefined
    }
}
