<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Amp\DeferredFuture;
use Amp\Socket\Socket;
use Revolt\EventLoop;
use Thesis\Nats\Config;
use Thesis\Nats\Exception\ConnectionIsNotAvailable;
use Thesis\Nats\Internal\Hooks;
use Thesis\Nats\Internal\Nkey\Signer;
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

    private readonly Hooks\ConcurrentProvider $hooks;

    private readonly PingPongHandler $pingpongs;

    private ?ConnectionInfo $info = null;

    private ConnectionState $state = ConnectionState::Idle;

    /**
     * Pending while the startup handshake waits for the server to answer the first PING.
     *
     * @var ?DeferredFuture<void>
     */
    private ?DeferredFuture $handshake = null;

    private readonly Signer $signer;

    public function __construct(
        private readonly Config $config,
        private readonly Socket $socket,
    ) {
        $this->framer = new Framer($this->socket);
        $this->hooks = new Hooks\ConcurrentProvider();
        $this->pingpongs = new PingPongHandler($this);
        $this->signer = new Signer();

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
            $this->execute(new Protocol\Connect(
                verbose: $this->config->verbose,
                pedantic: $this->config->pedantic,
                tlsRequired: false,
                name: $this->config->clientName,
                version: version(self::PACKAGE_NAME),
                user: $this->config->user,
                pass: $this->config->password,
                sig: $this->generateSignature($frame->nonce, $this->config->nkey),
                jwt: $this->config->jwt,
                noResponders: $this->config->noResponders,
                headers: $this->info->allowHeaders,
                nkey: $this->config->nkey,
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

    public function execute(Protocol\Frame $frame): void
    {
        /** @var ?DeferredFuture<Protocol\Frame> $deferred */
        $deferred = null;

        if ($this->config->verbose) {
            /** @var DeferredFuture<Protocol\Frame> $deferred */
            $deferred = new DeferredFuture();
            $this->queue->push($deferred);
        }

        $this->framer->writeFrame($frame);

        if ($deferred !== null) {
            $frame = $deferred->getFuture()->await();

            if ($frame instanceof Protocol\Err) {
                throw $frame->toException();
            }
        }
    }

    public function hooks(): Hooks\Provider
    {
        return $this->hooks;
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
                    foreach ($queue as $deferred) {
                        $deferred->error($e);
                    }

                    $handshake?->error($e);
                    $handshake = null;
                } finally {
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
     * @throws \Exception
     */
    private function generateSignature(?string $nonce, ?string $nkey): ?string
    {
        if ($nonce !== null && $nkey !== null) {
            return $this->signer->sign($nonce, $nkey);
        }

        return null;
    }
}
