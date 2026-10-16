<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use Thesis\Nats\Exception\AuthorizationViolation;
use Thesis\Nats\Exception\ConnectionWasClosed;
use Thesis\Nats\Exception\MaximumControlLineExceeded;
use Thesis\Nats\Exception\MaximumPayloadViolation;
use Thesis\Nats\Exception\PermissionViolated;
use Thesis\Nats\Internal\Connection\SocketConnection;
use Thesis\Nats\Internal\Id;
use function Amp\async;
use function Amp\Socket\listen;
use function Thesis\Nats\Internal\Protocol\encodeHeaders;

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
     * the client side too). A payload over the account's limit is the easiest way to get one: the server announces
     * its own max_payload (1 MiB) in INFO, the lower limit of the account (1 KiB) is known only to the server.
     */
    public function testFatalErrorClosesTheConnectionAndIsReported(): void
    {
        $client = $this->clientWithCredentials('limited', 'Pswd1');

        /** @var DeferredFuture<\Throwable> $error */
        $error = new DeferredFuture();
        $client->onError($error->complete(...));

        $subscription = $client->subscribe('events.*', static fn() => null);

        try {
            $client->publish('events.happens', new Message(str_repeat('x', 2048)));
        } catch (ConnectionWasClosed $e) {
            // The server may close the socket before the write completes, then the write fails with the server's reason.
            self::assertInstanceOf(MaximumPayloadViolation::class, $e->getPrevious());
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
        $client->stop();
    }

    /**
     * The server closes the connection on a message over its max_payload, failing every subscription of the client,
     * so the client refuses such a message itself. The limit covers the headers and the payload together.
     */
    public function testMessageOverMaxPayloadIsRefusedBeforeSending(): void
    {
        $client = $this->client();
        $id = Id\generateUniqueId();

        /** @var DeferredFuture<\Throwable> $error */
        $error = new DeferredFuture();
        $client->onError($error->complete(...));

        /** @var DeferredFuture<Delivery> $deferred */
        $deferred = new DeferredFuture();
        $subscription = $client->subscribe("{$id}.*", $deferred->complete(...));

        $headers = new Headers(['X-Id' => [$id]]);
        $maxPayload = 1024 * 1024;
        $limit = $maxPayload - \strlen(encodeHeaders($headers));

        try {
            $client->publish("{$id}.too-large", new Message(str_repeat('x', $limit + 1), $headers));
            self::fail('The message exceeds the max_payload of the server, publish must fail.');
        } catch (MaximumPayloadViolation $e) {
            self::assertStringContainsString((string) ($maxPayload + 1), $e->getMessage());
            self::assertStringContainsString((string) $maxPayload, $e->getMessage());
        }

        try {
            $client->request("{$id}.too-large", new Message(str_repeat('x', $maxPayload + 1)));
            self::fail('The message exceeds the max_payload of the server, request must fail.');
        } catch (MaximumPayloadViolation) {
        }

        // Nothing was sent: the connection and the subscription are alive, a message right at the limit goes through.
        $client->publish("{$id}.fits", new Message(str_repeat('x', $limit), $headers));

        $delivery = $deferred->getFuture()->await(new TimeoutCancellation(5));
        self::assertSame("{$id}.fits", $delivery->subject);
        self::assertSame($limit, \strlen($delivery->message->payload ?? ''));
        self::assertFalse($subscription->completed());
        self::assertFalse($error->isComplete());

        $client->stop();
    }

    public function testControlLineOverServerLimitIsReported(): void
    {
        $client = $this->client();

        /** @var DeferredFuture<\Throwable> $error */
        $error = new DeferredFuture();
        $client->onError($error->complete(...));

        // The server's max_control_line is 4 KiB by default, the subject alone is longer.
        $client->publish('events.' . str_repeat('x', 5000), new Message('ok'));

        $e = $error->getFuture()->await(new TimeoutCancellation(5));
        self::assertInstanceOf(MaximumControlLineExceeded::class, $e);
        self::assertStringStartsWith('maximum control line exceeded', $e->getMessage());

        $client->stop();
    }

    /**
     * The server stops reading, sends the error and closes the socket in the middle of a large write: the write fails
     * with the server's reason instead of a bare stream error. A fake server makes the timing deterministic.
     */
    public function testWriteInterruptedByServerFailsWithItsReason(): void
    {
        $server = listen('127.0.0.1:0');
        $address = $server->getAddress()->toString();
        \assert($address !== '');

        $future = async(static function () use ($server): void {
            $socket = $server->accept() ?? throw new \LogicException('The client did not connect.');

            // 64 MiB, so that the client sends a message larger than the socket buffers.
            $socket->write("INFO {\"version\":\"2.12.1\",\"headers\":true,\"max_payload\":67108864}\r\n");

            $buffer = '';

            while (!str_contains($buffer, "PING\r\n") && ($chunk = $socket->read()) !== null) {
                $buffer .= $chunk;
            }

            $socket->write("PONG\r\n");

            while (!str_contains($buffer, 'PUB ') && ($chunk = $socket->read()) !== null) {
                $buffer .= $chunk;
            }

            $socket->write("-ERR 'Maximum Payload Violation'\r\n");
            $socket->close();
        });

        $client = $this->clientWithUrls([$address]);

        try {
            $client->publish('events.happens', new Message(str_repeat('x', 16 * 1024 * 1024)));
            self::fail('The server closed the connection in the middle of the write, publish must fail.');
        } catch (ConnectionWasClosed $e) {
            self::assertInstanceOf(MaximumPayloadViolation::class, $e->getPrevious());
            self::assertStringContainsString('Maximum Payload Violation', $e->getMessage());
        } finally {
            $future->await(new TimeoutCancellation(5));
            $server->close();
        }
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
