<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Thesis\Nats\Config;
use Thesis\Nats\Exception\AuthorizationViolation;
use Thesis\Nats\Exception\ConnectionIsNotAvailable;
use Thesis\Nats\Exception\ConnectionWasClosed;
use Thesis\Nats\Exception\PermissionViolated;
use Thesis\Nats\Exception\ReconnectBufferExceeded;
use Thesis\Nats\Exception\ServerIsNotAvailable;
use Thesis\Nats\Internal\Hooks;
use Thesis\Nats\Internal\Protocol;
use Thesis\Nats\NatsException;
use function Amp\async;
use function Amp\delay;

/**
 * The connection of a client: a connection to one of the servers of the pool, replaced by a connection to the next one
 * once it is lost, the way nats.go does it. While reconnecting, the publishes are buffered and the subscriptions
 * wait to be sent again: the new connection gets the subscriptions first, the buffered commands after them.
 *
 * @internal
 */
final class ReconnectingConnection implements Connection
{
    public readonly Hooks\ConcurrentProvider $hooks;

    private ReconnectState $state = ReconnectState::Idle;

    private ?Connection $current = null;

    private ?ConnectionInfo $info = null;

    private readonly ServerPool $pool;

    /** @var list<Protocol\Frame> the commands sent while reconnecting */
    private array $pending = [];

    /** @var non-negative-int the size of the publishes among the pending commands */
    private int $pendingBytes = 0;

    /**
     * Pending while reconnecting, for the calls that cannot be buffered: a ping and a publish in verbose mode.
     *
     * @var ?DeferredFuture<void>
     */
    private ?DeferredFuture $reconnected = null;

    private readonly DeferredCancellation $closing;

    /**
     * @param \Closure(): list<Protocol\Frame> $subscriptions the commands that restore the subscriptions on a new connection
     */
    public function __construct(
        private readonly Config $config,
        private readonly ConnectionFactory $factory,
        private readonly \Closure $subscriptions,
    ) {
        $this->hooks = new Hooks\ConcurrentProvider();
        $this->pool = new ServerPool($config->urls, randomize: !$config->noRandomize, maxReconnects: $config->maxReconnects);
        $this->closing = new DeferredCancellation();
    }

    /**
     * Establishes the first connection: every server is tried once, in the order of the pool. A server that cannot
     * be reached is skipped, any other failure (e.g. an authentication error) is thrown at once.
     *
     * @throws \Throwable
     */
    public function start(): void
    {
        /** @var array<non-empty-string, ServerIsNotAvailable> $errors */
        $errors = [];

        foreach ($this->pool->servers() as $server) {
            try {
                $connection = $this->factory->connect($server->url);
            } catch (ServerIsNotAvailable $e) {
                $errors[$server->url] = $e;

                continue;
            }

            if ($this->state === ReconnectState::Closed) {
                $connection->close();

                throw new ConnectionWasClosed();
            }

            $this->pool->use($server);
            $this->attach($connection);
            $this->pool->discover($connection->info()->connectUrls);
            $this->state = ReconnectState::Connected;

            return;
        }

        throw new ServerIsNotAvailable(
            \sprintf('No available nats host: %s.', implode('; ', array_map(
                static fn(string $url, ServerIsNotAvailable $e): string => "{$url}: {$e->getMessage()}",
                array_keys($errors),
                array_values($errors),
            ))),
            previous: $errors !== [] ? $errors[array_key_last($errors)] : null,
        );
    }

    public function execute(Protocol\Frame|array $frames, ?Cancellation $cancellation = null): void
    {
        if ($this->state === ReconnectState::Reconnecting) {
            $frames = $frames instanceof Protocol\Frame ? [$frames] : $frames;

            // In verbose mode a publish is acknowledged, and a buffered one would be acknowledged by nobody.
            if (!$this->config->verbose || !self::publishes($frames)) {
                $this->buffer($frames);

                return;
            }

            $this->awaitReconnect($cancellation);
        }

        $this->connected()->execute($frames, $cancellation);
    }

    /**
     * Returns once connected: at once, or after the reconnect in progress.
     *
     * @throws ConnectionWasClosed if the client gives up reconnecting meanwhile or is closed
     * @throws CancelledException
     */
    public function awaitConnected(?Cancellation $cancellation = null): void
    {
        if ($this->state === ReconnectState::Reconnecting) {
            $this->awaitReconnect($cancellation);
        }

        $this->connected();
    }

    public function ping(?Cancellation $cancellation = null): float
    {
        if ($this->state === ReconnectState::Reconnecting) {
            $this->awaitReconnect($cancellation);
        }

        return $this->connected()->ping($cancellation);
    }

    public function info(): ConnectionInfo
    {
        return $this->info ?? throw new ConnectionIsNotAvailable();
    }

    public function close(?\Throwable $reason = null): void
    {
        $this->finish($reason);
    }

    private function connected(): Connection
    {
        if ($this->state === ReconnectState::Closed) {
            throw new ConnectionWasClosed();
        }

        return $this->current ?? throw new ConnectionIsNotAvailable();
    }

    /**
     * @throws ConnectionWasClosed if the client gives up reconnecting meanwhile
     * @throws CancelledException
     */
    private function awaitReconnect(?Cancellation $cancellation): void
    {
        $this->reconnected?->getFuture()->await($cancellation);
    }

    /**
     * @param non-empty-list<Protocol\Frame> $frames
     * @throws ReconnectBufferExceeded
     */
    private function buffer(array $frames): void
    {
        // Only the publishes count: the subscription commands are tiny, and a subscription must not fail on a full buffer.
        $bytes = 0;
        foreach ($frames as $frame) {
            if ($frame instanceof Protocol\Pub) {
                $bytes += \strlen($frame->encode());
            }
        }

        if ($bytes > 0 && $this->pendingBytes + $bytes > $this->config->reconnectBufferSize) {
            throw ReconnectBufferExceeded::forSize($this->config->reconnectBufferSize);
        }

        // Appended in place: copying the whole buffer on every publish would make a reconnect quadratic.
        array_push($this->pending, ...$frames);
        $this->pendingBytes += $bytes;
    }

    private function attach(Connection $connection): void
    {
        $this->current = $connection;
        $this->info = $connection->info();

        // The reader of the connection runs in the event loop: a strong reference from its hooks would keep this
        // connection, and the client behind it, alive for as long as the socket is open.
        $self = \WeakReference::create($this);

        $connection->hooks->onMessage(static function (Hooks\MessageReceived $event) use ($self): void {
            $self->get()?->hooks->dispatch($event);
        });
        $connection->hooks->onError(static function (Hooks\ErrorReceived $event) use (
            $self,
            $connection,
        ): void {
            $self->get()?->onServerError($connection, $event);
        });
        $connection->hooks->onInfo(static function (Hooks\InfoReceived $event) use (
            $self,
            $connection,
        ): void {
            $self->get()?->onServerInfo($connection, $event->info);
        });
        $connection->hooks->onClose(static function (Hooks\ConnectionClosed $event) use (
            $self,
            $connection,
        ): void {
            $self->get()?->onClosed($connection, $event->reason);
        });
    }

    /**
     * A permission violation is passed on, the client attributes it to a subscription. The errors another connection
     * may not have close the current one and the client reconnects, any other one closes the client.
     */
    private function onServerError(Connection $connection, Hooks\ErrorReceived $event): void
    {
        if ($connection !== $this->current) {
            return;
        }

        $e = $event->error->toException();

        if ($e instanceof PermissionViolated) {
            $this->hooks->dispatch($event);

            return;
        }

        if ($this->config->allowReconnect && $event->error->isReconnectable()) {
            $connection->close($e);

            return;
        }

        $this->finish($e);
    }

    private function onServerInfo(Connection $connection, Protocol\ServerInfo $info): void
    {
        if ($connection !== $this->current) {
            return;
        }

        if ($this->pool->discover($info->connectUrls ?? [])) {
            $this->discovered();
        }

        if ($info->ldm !== true) {
            return;
        }

        $this->hooks->dispatch(Hooks\LameDuckModeEntered::Event);

        // The server is going to close the connections: move to another one now, while the server still serves.
        // The next server is tried at once, the pause comes only after a whole round of the pool.
        // With nowhere to move to, the connection stays until the server closes it.
        if ($this->config->allowReconnect && $this->state === ReconnectState::Connected && $this->pool->count() > 1) {
            $connection->close();
        }
    }

    private function discovered(): void
    {
        $urls = $this->pool->urls();

        if ($urls !== []) {
            $this->hooks->dispatch(new Hooks\ServersDiscovered($urls));
        }
    }

    private function onClosed(Connection $connection, ?\Throwable $reason): void
    {
        if ($connection !== $this->current || $this->state !== ReconnectState::Connected) {
            return;
        }

        $this->current = null;

        if (!$this->config->allowReconnect) {
            $this->finish($reason);

            return;
        }

        $this->state = ReconnectState::Reconnecting;

        /** @var DeferredFuture<void> $reconnected */
        $reconnected = new DeferredFuture();
        // Nobody may be waiting when the client gives up.
        $reconnected->getFuture()->ignore();
        $this->reconnected = $reconnected;

        $this->hooks->dispatch(new Hooks\Disconnected($reason));

        $future = async($this->reconnect(...));
        $future->ignore();
    }

    /**
     * Tries the servers of the pool one by one, pausing once all of them have been tried, until one of them accepts
     * the connection or every one of them has used up its reconnects, see {@see ServerPool::next()}.
     */
    private function reconnect(): void
    {
        $cancellation = $this->closing->getCancellation();
        $tried = 0;
        $error = null;

        while (($server = $this->pool->next()) !== null) {
            // A round of the pool is tried at once, the pause comes after it, as in nats.go.
            if ($tried + 1 >= $this->pool->count()) {
                $tried = 0;

                try {
                    delay($this->pause(), cancellation: $cancellation);
                } catch (CancelledException) {
                    return;
                }
            } else {
                ++$tried;
            }

            if (!$this->reconnecting()) {
                return;
            }

            try {
                $connection = $this->factory->connect($server->url);
            } catch (\Throwable $e) {
                $error = $e;

                // Credentials rejected twice in a row by the same server are not going to be accepted, as in nats.go.
                if ($server->failed($e) && $e instanceof AuthorizationViolation) {
                    $this->finish($e);

                    return;
                }

                continue;
            }

            if (!$this->reconnecting()) {
                $connection->close();

                return;
            }

            try {
                $this->restore($connection);
            } catch (NatsException $e) {
                $error = $e;
                $server->failed($e);
                $this->current = null;
                $connection->close();

                continue;
            } catch (\Throwable $e) {
                // Not a connection failure: retrying would fail the same way, and the error would never surface.
                $this->current = null;
                $connection->close();
                $this->finish($e);

                return;
            }

            if (!$this->reconnecting()) {
                // Closed while the subscriptions were being restored: finish() has not seen the new connection yet.
                $connection->close();

                return;
            }

            $server->connected();

            if ($this->pool->discover($connection->info()->connectUrls)) {
                $this->discovered();
            }

            $this->state = ReconnectState::Connected;
            [$reconnected, $this->reconnected] = [$this->reconnected, null];
            $reconnected?->complete();
            $this->hooks->dispatch(Hooks\Reconnected::Event);

            return;
        }

        $this->finish(new ConnectionWasClosed('The connection was lost and no server is left to reconnect to.', previous: $error));
    }

    /**
     * Sends the subscriptions, then the commands buffered meanwhile, including the ones buffered while sending,
     * and waits for the PONG of a PING after them: SUB is not acknowledged, and the reconnect callbacks promise
     * the subscriptions active. A server that does not answer in time is treated as a failed attempt.
     * The subscriptions sent while reconnecting are buffered too and may be sent twice: the server ignores
     * a subscription with a sid it already has.
     *
     * @throws \Throwable
     */
    private function restore(Connection $connection): void
    {
        $this->attach($connection);

        $frames = ($this->subscriptions)();

        do {
            [$pending, $this->pending, $this->pendingBytes] = [$this->pending, [], 0];
            $frames = [...$frames, ...$pending];

            try {
                if ($frames !== []) {
                    try {
                        $connection->execute($frames);
                    } catch (PermissionViolated $e) {
                        // Verbose mode: a subscription the user is no longer allowed to have fails alone, as it would
                        // on its own. The acknowledgements of the commands after it are not awaited, so the PING below
                        // is what proves the server has the rest of them.
                        $this->hooks->dispatch(new Hooks\ErrorReceived(new Protocol\Err($e->getMessage() !== '' ? $e->getMessage() : 'Permissions Violation')));
                    }
                }

                $connection->ping(new TimeoutCancellation($this->config->connectionTimeout));
            } catch (CancelledException $e) {
                $this->requeue($pending);

                throw new ServerIsNotAvailable(
                    \sprintf('The nats server did not answer PING within %s seconds after the reconnect.', $this->config->connectionTimeout),
                    previous: $e,
                );
            } catch (\Throwable $e) {
                // Kept for the next server, together with the ones buffered while sending.
                $this->requeue($pending);

                throw $e;
            }

            $frames = [];
        } while ($this->hasPending());
    }

    /**
     * The state changes while the reconnect awaits, which PHPStan does not see in a property comparison.
     */
    private function reconnecting(): bool
    {
        return $this->state === ReconnectState::Reconnecting;
    }

    /**
     * Commands may be buffered while the restore awaits, which PHPStan does not see in a property comparison.
     */
    private function hasPending(): bool
    {
        return $this->pending !== [];
    }

    /**
     * @param list<Protocol\Frame> $frames sent before the ones pending now
     */
    private function requeue(array $frames): void
    {
        $this->pending = [...$frames, ...$this->pending];
        $this->pendingBytes = 0;

        foreach ($this->pending as $frame) {
            if ($frame instanceof Protocol\Pub) {
                $this->pendingBytes += \strlen($frame->encode());
            }
        }
    }

    /**
     * @return float in seconds
     */
    private function pause(): float
    {
        $jitter = $this->config->reconnectJitter > 0
            ? random_int(0, (int) ($this->config->reconnectJitter * 1_000_000)) / 1_000_000
            : 0;

        return $this->config->reconnectWait + $jitter;
    }

    /**
     * Closes the client for good: the reconnects stop, the buffered commands are dropped, and the calls waiting
     * for the reconnect fail.
     */
    private function finish(?\Throwable $reason = null): void
    {
        if ($this->state === ReconnectState::Closed) {
            return;
        }

        $this->state = ReconnectState::Closed;
        $this->closing->cancel();

        [$current, $this->current] = [$this->current, null];
        $this->pending = [];
        $this->pendingBytes = 0;

        [$reconnected, $this->reconnected] = [$this->reconnected, null];
        $reconnected?->error($reason instanceof ConnectionWasClosed ? $reason : new ConnectionWasClosed(previous: $reason));

        $current?->close();
        $this->hooks->dispatch(new Hooks\ConnectionClosed($reason));
    }

    /**
     * @param non-empty-list<Protocol\Frame> $frames
     */
    private static function publishes(array $frames): bool
    {
        return array_any($frames, static fn(Protocol\Frame $frame): bool => $frame instanceof Protocol\Pub);
    }
}
