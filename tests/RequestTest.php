<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use Thesis\Nats\Exception\RequestHasNoResponders;
use Thesis\Nats\Exception\RequestTimedOut;
use Thesis\Nats\Internal\Id;
use function Amp\async;
use function Amp\delay;

#[CoversClass(Client::class)]
final class RequestTest extends NatsTestCase
{
    public function testRequestTimesOut(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();
        $client->subscribe("{$id}.ignored", static fn() => null);

        $started = microtime(true);

        try {
            $client->request("{$id}.ignored", new Message('Are you ok?'), timeout: 0.2);
            self::fail('Nobody answers the request, it must time out.');
        } catch (RequestTimedOut $e) {
            self::assertInstanceOf(CancelledException::class, $e->getPrevious());
        }

        self::assertGreaterThanOrEqual(0.19, microtime(true) - $started, 'Timers may fire a bit early.');
        self::assertSame(0, self::pendingRequests($client));
    }

    public function testRequestTimesOutByConfigDefault(): void
    {
        $config = $this->config();
        $client = $this->clientWithConfig(new Config(
            urls: $config->urls,
            user: $config->user,
            password: $config->password,
            noResponders: $config->noResponders,
            requestTimeout: 0.2,
        ));

        $id = Id\generateUniqueId();
        $client->subscribe("{$id}.ignored", static fn() => null);
        $client->subscribe("{$id}.answered", static function (Delivery $delivery): void {
            $delivery->reply(new Message('ok'));
        });

        self::assertSame('ok', $client->request("{$id}.answered")->message->payload);

        $this->expectException(RequestTimedOut::class);
        $client->request("{$id}.ignored");
    }

    public function testExplicitTimeoutOverridesConfigDefault(): void
    {
        $config = $this->config();
        $client = $this->clientWithConfig(new Config(
            urls: $config->urls,
            user: $config->user,
            password: $config->password,
            noResponders: $config->noResponders,
            requestTimeout: 0.1,
        ));

        $id = Id\generateUniqueId();
        $client->subscribe("{$id}.slow", static function (Delivery $delivery): void {
            delay(0.3);
            $delivery->reply(new Message('ok'));
        });

        self::assertSame('ok', $client->request("{$id}.slow", timeout: 2)->message->payload);
    }

    public function testCallerCancellationIsNotReportedAsTimeout(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();
        $client->subscribe("{$id}.ignored", static fn() => null);

        $this->expectException(CancelledException::class);
        $client->request("{$id}.ignored", cancellation: new TimeoutCancellation(0.1), timeout: 5);
    }

    public function testRequestManyStopsAfterMaxMessages(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();
        self::respond($client, "{$id}.scatter", ['a', 'b', 'c']);

        $replies = self::payloads($client->requestMany("{$id}.scatter", new Message('?'), maxMessages: 2, timeout: 5));

        self::assertCount(2, $replies);
    }

    public function testRequestManyGathersUntilTimeout(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();
        self::respond($client, "{$id}.scatter", ['a', 'b', 'c']);

        $started = microtime(true);
        $replies = self::payloads($client->requestMany("{$id}.scatter", new Message('?'), timeout: 0.3));

        self::assertGreaterThanOrEqual(0.29, microtime(true) - $started, 'Timers may fire a bit early.');
        self::assertEqualsCanonicalizing(['a', 'b', 'c'], $replies);
    }

    public function testRequestManyStopsWhenRepliesStall(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();
        $client->subscribe("{$id}.scatter", static function (Delivery $delivery) use ($client): void {
            self::assertNotNull($delivery->replyTo);
            $client->publish($delivery->replyTo, new Message('1'));
            $client->publish($delivery->replyTo, new Message('2'));
            delay(0.5);
            $client->publish($delivery->replyTo, new Message('late'));
        });

        $started = microtime(true);
        $replies = self::payloads($client->requestMany("{$id}.scatter", new Message('?'), stall: 0.2, timeout: 5));

        self::assertSame(['1', '2'], $replies);
        self::assertLessThan(0.5, microtime(true) - $started);
    }

    public function testSlowConsumerIsNotMistakenForStall(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();
        $client->subscribe("{$id}.scatter", static function (Delivery $delivery) use ($client): void {
            self::assertNotNull($delivery->replyTo);

            for ($i = 0; $i < 10; ++$i) {
                $client->publish($delivery->replyTo, new Message((string) $i));
                delay(0.025);
            }
        });

        // The replies come more often than the stall, but the consumer takes them slower than they come:
        // they pile up in front of the full buffer, which is not a stall.
        $replies = $client->requestMany("{$id}.scatter", new Message('?'), stall: 0.12, timeout: 10, bufferSize: 1);

        $received = [];
        foreach ($replies as $reply) {
            $received[] = $reply->message->payload;
            delay(0.2);
        }

        self::assertSame(array_map(strval(...), range(0, 9)), $received);
    }

    public function testRequestManyStopsOnSentinel(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();
        $client->subscribe("{$id}.stream", static function (Delivery $delivery) use ($client): void {
            self::assertNotNull($delivery->replyTo);
            $client->publish($delivery->replyTo, new Message('1'));
            $client->publish($delivery->replyTo, new Message('2'));
            $client->publish($delivery->replyTo, new Message());
            $client->publish($delivery->replyTo, new Message('after the end'));
        });

        $replies = self::payloads($client->requestMany(
            "{$id}.stream",
            new Message('?'),
            sentinel: static fn(Delivery $delivery): bool => ($delivery->message->payload ?? '') === '',
            timeout: 5,
        ));

        self::assertSame(['1', '2'], $replies);
    }

    public function testRequestManyFailsWithoutResponders(): void
    {
        $client = $this->client();

        $this->expectException(RequestHasNoResponders::class);
        self::payloads($client->requestMany(Id\generateUniqueId() . '.nobody', new Message('?'), timeout: 5));
    }

    public function testRequestManyFailsOnCancellation(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();
        self::respond($client, "{$id}.scatter", ['a']);

        $cancellation = new DeferredCancellation();
        $replies = $client->requestMany("{$id}.scatter", new Message('?'), cancellation: $cancellation->getCancellation());

        $received = [];

        try {
            foreach ($replies as $reply) {
                $received[] = $reply->message->payload;
                $cancellation->cancel();
            }

            self::fail('The gathering must fail once cancelled.');
        } catch (CancelledException) {
        }

        self::assertSame(['a'], $received);
    }

    public function testStoppedRequestManyUnsubscribesFromInbox(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();
        self::respond($client, "{$id}.scatter", ['a', 'b']);

        $replies = $client->requestMany("{$id}.scatter", new Message('?'), timeout: 5);
        foreach ($replies as $_) {
            $replies->stop();
        }

        // Only the responders' subscriptions are left.
        self::assertCount(2, self::subscribers($client));
    }

    public function testRequestManyRequiresEndCondition(): void
    {
        $client = $this->client();

        $this->expectException(\InvalidArgumentException::class);
        $client->requestMany(Id\generateUniqueId() . '.scatter', new Message('?'));
    }

    /**
     * @param array{maxMessages?: int, stall?: float, timeout?: float} $arguments
     */
    #[TestWith([['timeout' => -1.0]])]
    #[TestWith([['timeout' => 0.0]])]
    #[TestWith([['timeout' => INF]])]
    #[TestWith([['timeout' => NAN]])]
    #[TestWith([['timeout' => 1.0, 'stall' => 0.0]])]
    #[TestWith([['timeout' => 1.0, 'stall' => INF]])]
    #[TestWith([['maxMessages' => 0]])]
    #[TestWith([['maxMessages' => -1]])]
    public function testRequestManyRejectsInvalidNumbersBeforeSubscribing(array $arguments): void
    {
        $client = $this->client();

        try {
            $client->requestMany(Id\generateUniqueId() . '.scatter', new Message('?'), ...$arguments);
            self::fail('The arguments are invalid, the request must be refused.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame([], self::subscribers($client));
    }

    #[TestWith([-1.0])]
    #[TestWith([0.0])]
    #[TestWith([INF])]
    #[TestWith([NAN])]
    public function testRequestRejectsInvalidTimeout(float $timeout): void
    {
        $client = $this->client();

        try {
            $client->request(Id\generateUniqueId() . '.nobody', new Message('?'), timeout: $timeout);
            self::fail('The timeout is invalid, the request must be refused.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(0, self::pendingRequests($client));
        self::assertSame([], self::subscribers($client));
    }

    public function testStallAloneIsNotEndCondition(): void
    {
        $client = $this->client();

        $this->expectException(\InvalidArgumentException::class);
        $client->requestMany(Id\generateUniqueId() . '.scatter', new Message('?'), stall: 0.05);
    }

    public function testSilentResponderIsEndedByTimeoutNotStall(): void
    {
        $client = $this->client();

        $id = Id\generateUniqueId();
        $client->subscribe("{$id}.silent", static fn() => null);

        $started = microtime(true);
        $replies = self::payloads($client->requestMany("{$id}.silent", new Message('?'), stall: 0.05, timeout: 0.3));

        self::assertSame([], $replies);
        self::assertGreaterThanOrEqual(0.29, microtime(true) - $started, 'The stall does not run before the first reply.');
    }

    public function testRequestManyEndsByConfigTimeout(): void
    {
        $config = $this->config();
        $client = $this->clientWithConfig(new Config(
            urls: $config->urls,
            user: $config->user,
            password: $config->password,
            noResponders: $config->noResponders,
            requestTimeout: 0.2,
        ));

        $id = Id\generateUniqueId();
        self::respond($client, "{$id}.scatter", ['a', 'b']);

        self::assertEqualsCanonicalizing(['a', 'b'], self::payloads($client->requestMany("{$id}.scatter", new Message('?'))));
    }

    /**
     * @param non-empty-string $subject
     * @param list<non-empty-string> $answers one responder per answer
     */
    private static function respond(Client $client, string $subject, array $answers): void
    {
        foreach ($answers as $answer) {
            $client->subscribe($subject, static function (Delivery $delivery) use ($answer): void {
                $delivery->reply(new Message($answer));
            });
        }
    }

    /**
     * @param iterable<Delivery> $deliveries
     * @return list<?string>
     */
    private static function payloads(iterable $deliveries): array
    {
        return async(static function () use ($deliveries): array {
            $payloads = [];
            foreach ($deliveries as $delivery) {
                $payloads[] = $delivery->message->payload;
            }

            return $payloads;
        })->await(new TimeoutCancellation(5));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function subscribers(Client $client): array
    {
        /** @var array<array-key, mixed> */
        return (new \ReflectionProperty(Client::class, 'subscribers'))->getValue($client);
    }
}
