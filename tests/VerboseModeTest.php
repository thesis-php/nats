<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use Thesis\Nats\Exception\ConnectionWasClosed;
use Thesis\Nats\Exception\PermissionViolated;
use Thesis\Nats\Internal\Connection\SocketConnection;
use Thesis\Nats\Internal\Id;
use function Amp\async;
use function Amp\delay;

/**
 * In verbose mode every command waits for its +OK/-ERR: the wait must end when the connection is closed
 * and must be cancellable, without breaking the order in which the acknowledgements are attributed.
 */
#[CoversClass(Client::class)]
#[CoversClass(SocketConnection::class)]
final class VerboseModeTest extends NatsTestCase
{
    private ?PausableProxy $proxy = null;

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->proxy?->stop();
    }

    public function testPublishFailsWhenServerClosesConnectionBeforeAcknowledgement(): void
    {
        $name = Id\generateUniqueId();
        $client = $this->verboseClient(clientName: $name);
        $id = Id\generateUniqueId();

        // The connection is established and acknowledges the commands.
        $client->publish("{$id}.first", new Message('ok'));

        $this->proxy()->pause();
        $publish = async($client->publish(...), "{$id}.second", new Message('ok'));
        // Let the publish write the PUB and start waiting for +OK, which never comes.
        delay(0.1);
        self::assertFalse($publish->isComplete());

        $this->kick($name);

        $this->expectException(ConnectionWasClosed::class);
        $publish->await(new TimeoutCancellation(5));
    }

    public function testPublishFailsWhenClientIsStoppedBeforeAcknowledgement(): void
    {
        $client = $this->verboseClient();
        $id = Id\generateUniqueId();

        $client->publish("{$id}.first", new Message('ok'));

        $this->proxy()->pause();
        $publish = async($client->publish(...), "{$id}.second", new Message('ok'));
        delay(0.1);
        self::assertFalse($publish->isComplete());

        $client->stop(new TimeoutCancellation(5));

        $this->expectException(ConnectionWasClosed::class);
        $publish->await(new TimeoutCancellation(5));
    }

    public function testCancelledPublishKeepsAcknowledgementOrder(): void
    {
        $client = $this->verboseClient();
        $id = Id\generateUniqueId();

        /** @var list<string> $received */
        $received = [];
        /** @var DeferredFuture<void> $done */
        $done = new DeferredFuture();
        $client->subscribe("{$id}.*", static function (Delivery $delivery) use (&$received, $done): void {
            $received[] = $delivery->message->payload ?? '';
            if (\count($received) === 2) {
                $done->complete();
            }
        });

        $this->proxy()->pause();

        $startedAt = microtime(true);

        try {
            $client->publish("{$id}.first", new Message('first'), cancellation: new TimeoutCancellation(0.5));
            self::fail('The server has not acknowledged the publish, it must be cancelled.');
        } catch (CancelledException) {
        }

        self::assertLessThan(3, microtime(true) - $startedAt);

        // The cancelled PUB reaches the server now: its +OK must be consumed by the cancelled publish,
        // not attributed to the next command.
        $this->proxy()->resume();

        $client->publish("{$id}.second", new Message('second'), cancellation: new TimeoutCancellation(5));

        $done->getFuture()->await(new TimeoutCancellation(5));
        self::assertSame(['first', 'second'], $received);

        $client->stop();
    }

    public function testErrorForCancelledPublishIsReportedToErrorCallback(): void
    {
        $client = $this->verboseClient(user: 'restricted', password: 'Pswd1');
        $id = Id\generateUniqueId();

        /** @var DeferredFuture<\Throwable> $error */
        $error = new DeferredFuture();
        $client->onError($error->complete(...));

        $client->publish("allowed.{$id}", new Message('ok'));

        $this->proxy()->pause();

        try {
            $client->publish("forbidden.{$id}", new Message('ok'), cancellation: new TimeoutCancellation(0.5));
            self::fail('The server has not acknowledged the publish, it must be cancelled.');
        } catch (CancelledException) {
        }

        $this->proxy()->resume();

        // Nobody waits for the -ERR of the cancelled publish any more: it is reported like in non-verbose mode.
        $e = $error->getFuture()->await(new TimeoutCancellation(5));
        self::assertInstanceOf(PermissionViolated::class, $e);
        self::assertStringContainsString("forbidden.{$id}", $e->getMessage());

        // The connection is still alive and the next acknowledgement goes to the next command.
        $client->publish("allowed.{$id}", new Message('ok'), cancellation: new TimeoutCancellation(5));

        $client->stop();
    }

    public function testPingsAreNotAwaitedAsAcknowledgedCommands(): void
    {
        // The server answers PING with PONG and never acknowledges PING or PONG with +OK, even in verbose mode:
        // the pings sent in between must not shift the queue of the commands awaiting their +OK/-ERR.
        $client = $this->verboseClient(user: 'restricted', password: 'Pswd1', ping: 100);
        $id = Id\generateUniqueId();

        /** @var list<\Throwable> $errors */
        $errors = [];
        $client->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e;
        });

        $client->publish("allowed.{$id}", new Message('ok'), cancellation: new TimeoutCancellation(5));

        // Let a few pings go out (and the pongs come back) between the commands.
        delay(0.35);

        try {
            $client->publish("forbidden.{$id}", new Message('ok'), cancellation: new TimeoutCancellation(5));
            self::fail('The -ERR must be attributed to the publish into the forbidden subject.');
        } catch (PermissionViolated $e) {
            self::assertStringContainsString("forbidden.{$id}", $e->getMessage());
        }

        delay(0.35);

        // The next +OK goes to the next command, not to a ping sent before it.
        $client->publish("allowed.{$id}", new Message('ok'), cancellation: new TimeoutCancellation(5));

        self::assertSame([], $errors);

        $client->stop();
    }

    private function proxy(): PausableProxy
    {
        return $this->proxy ??= PausableProxy::start($this->natsAddress());
    }

    /**
     * @param non-empty-string $clientName
     * @param ?non-empty-string $user
     * @param ?non-empty-string $password
     * @param ?positive-int $ping the ping interval in milliseconds, the default one when null
     */
    private function verboseClient(
        string $clientName = 'thesis/nats',
        ?string $user = null,
        ?string $password = null,
        ?int $ping = null,
    ): Client {
        $config = $this->config();

        return $this->clientWithConfig(new Config(
            urls: [$this->proxy()->address],
            verbose: true,
            user: $user ?? $config->user,
            password: $password ?? $config->password,
            noResponders: true,
            ping: $ping ?? $config->ping,
            clientName: $clientName,
        ));
    }
}
