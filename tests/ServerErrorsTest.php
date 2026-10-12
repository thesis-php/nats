<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\ByteStream\ClosedException;
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use Thesis\Nats\Exception\AuthorizationViolation;
use Thesis\Nats\Exception\MaximumPayloadViolation;
use Thesis\Nats\Exception\PermissionViolated;
use Thesis\Nats\Internal\Connection\SocketConnection;
use Thesis\Nats\Internal\Id;

/**
 * The server errors (-ERR) must be visible to the user in both verbose and non-verbose modes.
 */
#[CoversClass(Client::class)]
#[CoversClass(SocketConnection::class)]
final class ServerErrorsTest extends NatsTestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function testAuthorizationViolationIsThrownFromTheFirstCall(bool $verbose): void
    {
        $client = $this->clientWithCredentials('user', 'wrong', $verbose);

        try {
            $client->publish('events.happens', new Message('ok'));
            self::fail('The credentials are invalid, publish must fail.');
        } catch (AuthorizationViolation) {
        }

        // The next call must not silently succeed over a new connection either.
        self::expectException(AuthorizationViolation::class);
        $client->subscribe('events.*', static fn() => null);
    }

    public function testInvalidJwtIsRejected(): void
    {
        $config = $this->jwtConfig();
        $client = $this->clientWithConfig(new Config(
            urls: $config->urls,
            jwt: 'eyJhbGciOiJub25lIn0.eyJzdWIiOiJ0ZXN0In0.',
            nkey: $config->nkey,
        ));

        self::expectException(AuthorizationViolation::class);
        $client->publish('events.happens', new Message('ok'));
    }

    public function testSubscribePermissionViolationFailsTheSubscriptionOnly(): void
    {
        $client = $this->clientWithCredentials('restricted', 'Pswd1');

        $id = Id\generateUniqueId();

        /** @var DeferredFuture<Delivery> $deferred */
        $deferred = new DeferredFuture();

        $allowed = $client->subscribe("allowed.{$id}", $deferred->complete(...));
        $forbidden = $client->subscribe("forbidden.{$id}", static fn() => null);

        try {
            $forbidden->awaitCompletion(new TimeoutCancellation(5));
            self::fail('The subscription is not permitted, it must fail.');
        } catch (PermissionViolated $e) {
            self::assertStringContainsString("forbidden.{$id}", $e->getMessage());
        }

        // The connection and the other subscriptions are not affected.
        $client->publish("allowed.{$id}", new Message('ok'));
        self::assertSame('ok', $deferred->getFuture()->await(new TimeoutCancellation(5))->message->payload);
        self::assertFalse($allowed->completed());

        $client->stop();
    }

    public function testSubscribePermissionViolationIsThrownInVerboseMode(): void
    {
        $client = $this->clientWithCredentials('restricted', 'Pswd1', verbose: true);

        self::expectException(PermissionViolated::class);
        $client->subscribe('forbidden.' . Id\generateUniqueId(), static fn() => null);
    }

    public function testPublishPermissionViolationIsReportedToErrorCallback(): void
    {
        $client = $this->clientWithCredentials('restricted', 'Pswd1');

        $id = Id\generateUniqueId();

        /** @var DeferredFuture<\Throwable> $error */
        $error = new DeferredFuture();
        $client->onError($error->complete(...));

        /** @var DeferredFuture<Delivery> $deferred */
        $deferred = new DeferredFuture();
        $client->subscribe("allowed.{$id}", $deferred->complete(...));

        // The server drops the message, keeps the connection and sends -ERR, which has no call to be attributed to.
        $client->publish("forbidden.{$id}", new Message('ok'));

        $e = $error->getFuture()->await(new TimeoutCancellation(5));
        self::assertInstanceOf(PermissionViolated::class, $e);
        self::assertStringContainsString("forbidden.{$id}", $e->getMessage());

        // The connection is still alive.
        $client->publish("allowed.{$id}", new Message('ok'));
        self::assertSame('ok', $deferred->getFuture()->await(new TimeoutCancellation(5))->message->payload);

        $client->stop();
    }

    public function testPublishPermissionViolationIsThrownInVerboseMode(): void
    {
        $client = $this->clientWithCredentials('restricted', 'Pswd1', verbose: true);

        self::expectException(PermissionViolated::class);
        $client->publish('forbidden.' . Id\generateUniqueId(), new Message('ok'));
    }

    /**
     * The errors other than the permission violations make the server close the connection (nats.go closes it from
     * the client side too). The payload over max_payload (1 MiB by default) is the easiest way to get one.
     */
    public function testFatalErrorClosesTheConnectionAndIsReported(): void
    {
        $client = $this->client();

        /** @var DeferredFuture<\Throwable> $error */
        $error = new DeferredFuture();
        $client->onError($error->complete(...));

        $subscription = $client->subscribe('events.*', static fn() => null);

        try {
            $client->publish('events.happens', new Message(str_repeat('x', 1024 * 1024 + 1)));
        } catch (ClosedException) {
            // The server closes the socket as soon as it reads the PUB line, usually in the middle of the payload write.
        }

        self::assertInstanceOf(MaximumPayloadViolation::class, $error->getFuture()->await(new TimeoutCancellation(5)));

        // The subscriptions are failed with the same error, before it is reported.
        self::assertTrue($subscription->completed());

        try {
            $subscription->awaitCompletion(new TimeoutCancellation(5));
            self::fail('The connection was closed by the server, the subscription must fail.');
        } catch (MaximumPayloadViolation) {
        }

        // The connection was closed, so the next call establishes a new one.
        $client->publish('events.happens', new Message('ok'));
    }

    /**
     * @param non-empty-string $user
     * @param non-empty-string $password
     */
    private function clientWithCredentials(string $user, string $password, bool $verbose = false): Client
    {
        return $this->clientWithConfig(new Config(
            urls: [$this->natsAddress()],
            verbose: $verbose,
            user: $user,
            password: $password,
            noResponders: true,
        ));
    }
}
