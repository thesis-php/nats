<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Socket\Socket;
use Revolt\EventLoop;
use Thesis\Nats\Config;
use Thesis\Nats\Exception\ConnectionIsNotAvailable;
use Thesis\Nats\Exception\ConnectionWasClosed;
use Thesis\Nats\Internal\Hooks;
use Thesis\Nats\Internal\Nkey\KeyPair;
use Thesis\Nats\Internal\Protocol;
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
     * @param ?KeyPair $keypair the NKey of the user: the server gets its public key and the nonce signed with the secret one,
     *                          the seed itself never leaves the client
     */
    public function __construct(
        private readonly Config $config,
        private readonly Socket $socket,
        private readonly ?KeyPair $keypair = null,
    ) {
        $this->framer = new Framer($this->socket);
        $this->hooks = new Hooks\ConcurrentProvider();
        $this->pingpongs = new PingPongHandler($this);

        /** @var \SplQueue<DeferredFuture<Protocol\Frame>> $queue */
        $queue = new \SplQueue();
        $this->queue = $queue;
    }

    /**
     * @throws \Throwable
     */
    public function startup(): void
    {
        $frame = $this->framer->readFrame() ?? throw new ConnectionIsNotAvailable();

        if (!$frame instanceof Protocol\ServerInfo) {
            throw new \UnexpectedValueException(
                \sprintf('An unexpected "%s" startup frame received.', $frame::class),
            );
        }

        if ($this->state !== ConnectionState::Alive) {
            $this->run();
        }

        $this->info ??= ConnectionInfo::fromServerInfo($frame);

        /** @var DeferredFuture<void> $handshake */
        $handshake = new DeferredFuture();
        $this->handshake = $handshake;

        try {
            $this->connect(new Protocol\Connect(
                verbose: $this->config->verbose,
                pedantic: $this->config->pedantic,
                tlsRequired: false,
                name: $this->config->clientName,
                version: version(self::PACKAGE_NAME),
                user: $this->config->user,
                pass: $this->config->password,
                sig: $this->keypair !== null && $frame->nonce !== null && $frame->nonce !== '' ? $this->keypair->sign($frame->nonce) : null,
                jwt: $this->config->jwt,
                noResponders: $this->config->noResponders,
                headers: $this->info->allowHeaders,
                nkey: $this->keypair?->publicKey,
            ));

            // In non-verbose mode the server does not acknowledge CONNECT: it stays silent on success and answers
            // with -ERR (e.g. Authorization Violation) before closing the socket on failure. A PING forces a reply
            // (PONG or -ERR), so that the errors surface from here instead of being lost, the same way nats.go does it.
            $this->framer->writeFrame(Protocol\Ping::Frame);
            $handshake->getFuture()->await();
        } finally {
            $this->handshake = null;
            $handshake->getFuture()->ignore();
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

    public function info(): ConnectionInfo
    {
        return $this->info ?? throw new ConnectionIsNotAvailable();
    }

    public function close(): void
    {
        if ($this->state === ConnectionState::Closed || $this->state === ConnectionState::GracefulClosed) {
            return;
        }

        $this->state = ConnectionState::GracefulClosed;
        $this->socket->close();
        self::failPending($this->queue, new ConnectionWasClosed());
        $this->hooks->dispatch(Hooks\ConnectionClosed::Event);
    }

    private function run(): void
    {
        $framer = $this->framer;
        $queue = $this->queue;
        $hooks = $this->hooks;
        $socket = $this->socket;
        $state = &$this->state;
        $handshake = &$this->handshake;

        EventLoop::queue(static function () use (
            $framer,
            $queue,
            $hooks,
            $socket,
            &$state,
            &$handshake,
        ): void {
            while ($state === ConnectionState::Alive) {
                $error = null;

                try {
                    while (($frame = $framer->readFrame()) !== null) {
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

                        if ($frame instanceof Protocol\Pong && $handshake !== null) {
                            $handshake->complete();
                            $handshake = null;
                        }

                        $event = match (true) {
                            $frame instanceof Protocol\Ping => Hooks\PingReceived::Event,
                            $frame instanceof Protocol\Pong => Hooks\PongReceived::Event,
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

                    if ($state !== ConnectionState::GracefulClosed) { // @phpstan-ignore notIdentical.alwaysTrue
                        $state = ConnectionState::Closed;
                        $socket->close();
                        $hooks->dispatch(Hooks\ConnectionClosed::Event);
                    }

                    // The server closed the connection without answering the handshake.
                    $handshake?->error(new ConnectionIsNotAvailable());
                    $handshake = null;
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
    private function connect(Protocol\Connect $connect): void
    {
        try {
            $this->execute($connect);
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
     * @param \SplQueue<DeferredFuture<Protocol\Frame>> $queue
     */
    private static function failPending(\SplQueue $queue, \Throwable $e): void
    {
        while (!$queue->isEmpty()) {
            $queue->shift()->error($e);
        }
    }
}
