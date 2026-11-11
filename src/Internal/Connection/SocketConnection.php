<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Socket\Socket;
use Amp\Socket\SocketException;
use Amp\Socket\TlsState;
use Amp\TimeoutCancellation;
use Revolt\EventLoop;
use Thesis\Nats\Config;
use Thesis\Nats\Exception\ConnectionIsNotAvailable;
use Thesis\Nats\Exception\ConnectionWasClosed;
use Thesis\Nats\Exception\ServerIsNotAvailable;
use Thesis\Nats\Exception\TlsHandshakeFailed;
use Thesis\Nats\Exception\TlsIsNotAvailable;
use Thesis\Nats\Internal\Hooks;
use Thesis\Nats\Internal\Protocol;
use Thesis\Nats\NatsException;
use function Amp\async;
use function Thesis\Package\version;

/**
 * @internal
 */
final class SocketConnection implements Connection
{
    private const string PACKAGE_NAME = 'thesis/nats';

    private readonly Framer $framer;

    /** @var \SplQueue<DeferredFuture<Protocol\Frame>> */
    private readonly \SplQueue $queue;

    public readonly Hooks\ConcurrentProvider $hooks;

    private readonly PingPongHandler $pingpongs;

    private ?ConnectionInfo $info = null;

    private ConnectionState $state = ConnectionState::Idle;

    /**
     * Pending while the startup handshake waits for the server to answer the first PING.
     *
     * @var ?DeferredFuture<void>
     */
    private ?DeferredFuture $handshake = null;

    /**
     * One entry per PING written after the handshake, in the order the server answers them with PONG:
     * the deferred of a {@see ping()} that waits for its PONG, null for a keep-alive PING nobody waits for.
     *
     * @var \SplQueue<?DeferredFuture<void>>
     */
    private readonly \SplQueue $pongs;

    /**
     * Completed once the reader stops, with the error the server sent right before closing the connection, if any.
     *
     * @var DeferredFuture<?\Throwable>
     */
    private readonly DeferredFuture $termination;

    /**
     * @param Credentials $credentials the JWT and the NKey of the user: the server gets the public key and the nonce
     *                                 signed with the secret one, the seed itself never leaves the client
     */
    public function __construct(
        private readonly Config $config,
        private readonly Socket $socket,
        private readonly Credentials $credentials,
    ) {
        $this->framer = new Framer($this->socket);
        $this->hooks = new Hooks\ConcurrentProvider();
        $this->pingpongs = new PingPongHandler($this);

        /** @var \SplQueue<DeferredFuture<Protocol\Frame>> $queue */
        $queue = new \SplQueue();
        $this->queue = $queue;

        /** @var \SplQueue<?DeferredFuture<void>> $pongs */
        $pongs = new \SplQueue();
        $this->pongs = $pongs;

        /** @var DeferredFuture<?\Throwable> $termination */
        $termination = new DeferredFuture();
        $this->termination = $termination;
    }

    /**
     * @throws \Throwable
     */
    public function startup(): void
    {
        // The connection timeout bounds the handshake too: a peer that accepts the socket and stays silent
        // (a hung server, a proxy or a load balancer without a backend) must not make the connection attempt hang.
        $timeout = $this->config->connectionTimeout;

        try {
            $this->handshake(new TimeoutCancellation($timeout));
        } catch (CancelledException $e) {
            throw new ServerIsNotAvailable(
                \sprintf('The nats server did not complete the handshake within %s seconds.', $timeout),
                previous: $e,
            );
        } catch (ConnectionIsNotAvailable $e) {
            // The TLS alert the server sends before closing is not reported by PHP: the client sees a bare close.
            if ($this->socket->getTlsState() === TlsState::Enabled) {
                throw new ConnectionIsNotAvailable(
                    'The nats server closed the TLS connection during the handshake: it may not accept the client certificate, or its absence.',
                    previous: $e,
                );
            }

            throw $e;
        }

        if (($interval = $this->config->ping) !== null) {
            $this->pingpongs->startup($interval, $this->config->maxPings);
        }
    }

    public function execute(Protocol\Frame|array $frames, ?Cancellation $cancellation = null): void
    {
        $frames = $frames instanceof Protocol\Frame ? [$frames] : $frames;

        /** @var list<Future<Protocol\Frame>> $acks */
        $acks = [];

        foreach ($frames as $frame) {
            if ($frame instanceof Protocol\Ping) {
                // Its PONG must not be taken for the one a ping() call waits for.
                $this->pongs->push(null);
            }
        }

        if ($this->config->verbose) {
            foreach ($frames as $frame) {
                if (self::isAcknowledged($frame)) {
                    /** @var DeferredFuture<Protocol\Frame> $deferred */
                    $deferred = new DeferredFuture();
                    $this->queue->push($deferred);
                    $acks[] = $deferred->getFuture();
                }
            }
        }

        try {
            $this->framer->writeFrame($frames);
        } catch (\Throwable $e) {
            // The deferreds are failed or dropped together with the rest of the queue once the connection is closed.
            foreach ($acks as $ack) {
                $ack->ignore();
            }

            if ($e instanceof StreamException) {
                throw $this->closedWhileWriting($e);
            }

            throw $e;
        }

        foreach ($acks as $i => $ack) {
            try {
                $frame = $ack->await($cancellation);
            } catch (CancelledException $e) {
                $this->routeLateErrors(\array_slice($acks, $i));

                throw $e;
            }

            if ($frame instanceof Protocol\Err) {
                $this->routeLateErrors(\array_slice($acks, $i + 1));

                throw $frame->toException();
            }
        }
    }

    public function ping(?Cancellation $cancellation = null): float
    {
        /** @var DeferredFuture<void> $pong */
        $pong = new DeferredFuture();
        // A cancelled ping leaves its deferred in the queue for its PONG, which may never come once the connection is closed.
        $pong->getFuture()->ignore();
        $this->pongs->push($pong);

        $start = hrtime(true);

        // The write waits for room in the socket buffer, which a stalled peer may never free: it runs on its own,
        // so that the cancellation bounds it too. A cancelled write still completes in the background,
        // keeping its PING in line with the deferred above, or fails together with the connection.
        $framer = $this->framer;
        $write = async(static fn() => $framer->writeFrame(Protocol\Ping::Frame));
        $write->ignore();

        try {
            $write->await($cancellation);
        } catch (StreamException $e) {
            throw $this->closedWhileWriting($e);
        }

        $pong->getFuture()->await($cancellation);

        return (hrtime(true) - $start) / 1e9;
    }

    /**
     * Reads INFO, sends CONNECT and waits for the server to answer the PING that follows it.
     *
     * @throws \Throwable
     */
    private function handshake(Cancellation $cancellation): void
    {
        $tls = $this->config->tls;

        if ($tls?->handshakeFirst === true) {
            $this->secure($cancellation);
        }

        $frame = $this->framer->readStartupFrame($cancellation) ?? throw new ConnectionIsNotAvailable();

        if (!$frame instanceof Protocol\ServerInfo) {
            throw new \UnexpectedValueException(
                \sprintf('An unexpected "%s" startup frame received.', $frame::class),
            );
        }

        if ($tls !== null && !$tls->handshakeFirst && $frame->tlsRequired !== true && $frame->tlsAvailable !== true) {
            throw new TlsIsNotAvailable('The client requires TLS, but the nats server does not offer it.');
        }

        // The server that requires TLS gets it without the TLS options too, as nats.go does. Nothing reads the socket
        // until the reader starts, so the upgrade does not race with it.
        if ($this->socket->getTlsState() === TlsState::Disabled && ($tls !== null || $frame->tlsRequired === true)) {
            // The server says nothing else before the handshake: plain bytes past INFO would be taken
            // for the encrypted ones (e.g. an injected PONG for the answer to CONNECT).
            if ($this->framer->hasUnread()) {
                throw new TlsHandshakeFailed('The nats server sent unexpected plain data before the TLS handshake.');
            }

            $this->secure($cancellation);
        }

        if ($this->state !== ConnectionState::Alive) {
            $this->run();
        }

        $this->info ??= ConnectionInfo::fromServerInfo($frame);

        /** @var DeferredFuture<void> $handshake */
        $handshake = new DeferredFuture();
        $this->handshake = $handshake;
        $keypair = $this->credentials->keypair;

        try {
            $this->connect(new Protocol\Connect(
                verbose: $this->config->verbose,
                pedantic: $this->config->pedantic,
                tlsRequired: $this->socket->getTlsState() === TlsState::Enabled,
                name: $this->config->clientName,
                version: version(self::PACKAGE_NAME),
                // Asks the server for the INFO updates: the servers of the cluster and the lame duck mode notification.
                protocol: Protocol\Proto::Cluster,
                authToken: $this->config->token,
                user: $this->config->user,
                pass: $this->config->password,
                sig: $keypair !== null && $frame->nonce !== null && $frame->nonce !== '' ? $keypair->sign($frame->nonce) : null,
                jwt: $this->credentials->jwt,
                // The server rejects no_responders without headers: the no responders status is a header.
                noResponders: $this->config->noResponders && $this->info->allowHeaders,
                headers: $this->info->allowHeaders,
                nkey: $keypair?->publicKey,
            ), $cancellation);

            // In non-verbose mode the server does not acknowledge CONNECT: it stays silent on success and answers
            // with -ERR (e.g. Authorization Violation) before closing the socket on failure. A PING forces a reply
            // (PONG or -ERR), so that the errors surface from here instead of being lost, the same way nats.go does it.
            try {
                $this->framer->writeFrame(Protocol\Ping::Frame);
            } catch (StreamException $e) {
                // The server's own error (e.g. Authorization Violation) wins, as when the write gets through.
                $closed = $this->closedWhileWriting($e);

                throw $closed->getPrevious() instanceof NatsException ? $closed->getPrevious() : new ConnectionIsNotAvailable(previous: $closed);
            }

            $handshake->getFuture()->await($cancellation);
        } finally {
            $this->handshake = null;
            $handshake->getFuture()->ignore();
        }
    }

    /**
     * @throws TlsHandshakeFailed
     */
    private function secure(Cancellation $cancellation): void
    {
        // A cancelled handshake is let through: it is the connection timeout, not a TLS failure.
        try {
            $this->socket->setupTls($cancellation);
        } catch (SocketException|StreamException $e) {
            throw new TlsHandshakeFailed("The TLS handshake with the nats server failed: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * The server closes the socket right after sending a fatal error, often in the middle of a write: amp shuts
     * the writing side down only, so the reader still gets the error, and the write fails with the server's reason
     * rather than a bare stream error.
     */
    private function closedWhileWriting(StreamException $e): ConnectionWasClosed
    {
        try {
            $reason = $this->termination->getFuture()->await(new TimeoutCancellation($this->config->connectionTimeout));
        } catch (CancelledException) {
            $reason = null;
        }

        return $reason !== null
            ? new ConnectionWasClosed("The connection was closed by the server: {$reason->getMessage()}", previous: $reason)
            : new ConnectionWasClosed("The connection was closed: {$e->getMessage()}", previous: $e);
    }

    public function info(): ConnectionInfo
    {
        return $this->info ?? throw new ConnectionIsNotAvailable();
    }

    public function close(?\Throwable $reason = null): void
    {
        if ($this->state === ConnectionState::Closed || $this->state === ConnectionState::GracefulClosed) {
            return;
        }

        $this->state = ConnectionState::GracefulClosed;
        $this->socket->close();
        self::failPending($this->queue, new ConnectionWasClosed());
        self::failPongs($this->pongs, new ConnectionWasClosed());
        $this->hooks->dispatch(new Hooks\ConnectionClosed($reason));
    }

    private function run(): void
    {
        $framer = $this->framer;
        $queue = $this->queue;
        $pongs = $this->pongs;
        $hooks = $this->hooks;
        $socket = $this->socket;
        $termination = $this->termination;
        $state = &$this->state;
        $handshake = &$this->handshake;

        EventLoop::queue(static function () use (
            $framer,
            $queue,
            $pongs,
            $hooks,
            $socket,
            $termination,
            &$state,
            &$handshake,
        ): void {
            // The server sends a fatal error right before closing the connection, so it is the last frame read.
            $last = null;

            while ($state === ConnectionState::Alive) {
                $error = null;

                try {
                    while (($frame = $framer->readFrame()) !== null) {
                        $last = $frame;

                        if ($frame instanceof Protocol\Ok || $frame instanceof Protocol\Err) {
                            if (!$queue->isEmpty()) {
                                // Verbose mode: the acknowledgement belongs to the oldest command awaiting one.
                                $queue->shift()->complete($frame);
                            } elseif ($frame instanceof Protocol\Err) {
                                if ($handshake !== null) {
                                    $handshake->error($frame->toException());
                                    $handshake = null;
                                } else {
                                    $hooks->dispatch(new Hooks\ErrorReceived($frame));
                                }
                            }

                            continue;
                        }

                        if ($frame instanceof Protocol\Pong) {
                            if ($handshake !== null) {
                                $handshake->complete();
                                $handshake = null;
                            } elseif (!$pongs->isEmpty()) {
                                $pongs->shift()?->complete();
                            }
                        }

                        $event = match (true) {
                            $frame instanceof Protocol\Ping => Hooks\PingReceived::Event,
                            $frame instanceof Protocol\Pong => Hooks\PongReceived::Event,
                            // The INFO of the handshake is read before the reader starts, so any INFO here is an update.
                            $frame instanceof Protocol\ServerInfo => new Hooks\InfoReceived($frame),
                            $frame instanceof Protocol\Msg => new Hooks\MessageReceived(
                                subject: $frame->subject,
                                sid: $frame->sid,
                                replyTo: $frame->replyTo,
                                payload: $frame->message->payload,
                                headers: $frame->message->headers,
                            ),
                            default => null,
                        };

                        if ($event !== null) {
                            $hooks->dispatch($event);
                        }
                    }
                } catch (\Throwable $e) {
                    $error = $e;

                    $handshake?->error($e);
                    $handshake = null;
                } finally {
                    // Nothing acknowledges the commands still awaiting +OK any more.
                    self::failPending($queue, $error ?? new ConnectionWasClosed());
                    self::failPongs($pongs, $error ?? new ConnectionWasClosed());

                    if ($state !== ConnectionState::GracefulClosed) { // @phpstan-ignore notIdentical.alwaysTrue
                        $state = ConnectionState::Closed;
                        $socket->close();
                        $hooks->dispatch(new Hooks\ConnectionClosed($error));
                    }

                    // The server closed the connection without answering the handshake.
                    $handshake?->error(new ConnectionIsNotAvailable());
                    $handshake = null;

                    if (!$termination->isComplete()) {
                        $termination->complete($last instanceof Protocol\Err ? $last->toException() : null);
                    }
                }
            }
        });

        $this->state = ConnectionState::Alive;
    }

    /**
     * Sends CONNECT, which the server acknowledges in verbose mode.
     *
     * @throws \Throwable
     */
    private function connect(Protocol\Connect $connect, Cancellation $cancellation): void
    {
        try {
            $this->execute($connect, $cancellation);
        } catch (ConnectionWasClosed $e) {
            // The server closed the connection without acknowledging CONNECT, the same as without answering PING.
            throw new ConnectionIsNotAvailable(previous: $e);
        }
    }

    /**
     * The deferreds of the acknowledgements nobody awaits anymore stay in the queue, so that they are not attributed
     * to the next commands. An -ERR that arrives for them later is routed the same way as in non-verbose mode.
     *
     * @param list<Future<Protocol\Frame>> $acks
     */
    private function routeLateErrors(array $acks): void
    {
        $hooks = $this->hooks;

        foreach ($acks as $ack) {
            $ack
                ->map(static function (Protocol\Frame $frame) use ($hooks): void {
                    if ($frame instanceof Protocol\Err) {
                        $hooks->dispatch(new Hooks\ErrorReceived($frame));
                    }
                })
                ->ignore();
        }
    }

    /**
     * Whether the server answers the frame with +OK/-ERR in verbose mode. PING and PONG are the exception:
     * PING is answered with PONG and PONG with nothing, so waiting for their acknowledgement would consume
     * the one of the next command and leave that command waiting for the +OK of the one after it.
     */
    private static function isAcknowledged(Protocol\Frame $frame): bool
    {
        return !$frame instanceof Protocol\Ping && !$frame instanceof Protocol\Pong;
    }

    /**
     * @param \SplQueue<?DeferredFuture<void>> $pongs
     */
    private static function failPongs(\SplQueue $pongs, \Throwable $e): void
    {
        while (!$pongs->isEmpty()) {
            $pongs->shift()?->error($e);
        }
    }

    /**
     * @param \SplQueue<DeferredFuture<Protocol\Frame>> $queue
     */
    private static function failPending(\SplQueue $queue, \Throwable $e): void
    {
        while (!$queue->isEmpty()) {
            $queue->shift()->error($e);
        }
    }
}
