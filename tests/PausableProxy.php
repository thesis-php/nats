<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Socket\ServerSocket;
use Amp\Socket\Socket;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\Socket\connect;
use function Amp\Socket\listen;

/**
 * A tcp proxy to the real nats server that can hold back what the clients send, so that a command
 * stays in flight (sent by the client, but not seen by the server) for as long as the test needs.
 * What the server sends is always forwarded.
 */
final class PausableProxy
{
    /** @var ?DeferredFuture<void> */
    private ?DeferredFuture $paused = null;

    /** @var ?array{non-empty-string, DeferredFuture<void>} */
    private ?array $pauseBefore = null;

    /** @var list<Socket> */
    private array $sockets = [];

    /**
     * @param non-empty-string $address
     */
    private function __construct(
        private readonly ServerSocket $server,
        public readonly string $address,
    ) {}

    /**
     * @param non-empty-string $upstream
     * @param non-empty-string $listen
     */
    public static function start(string $upstream, string $listen = '127.0.0.1:0'): self
    {
        $server = listen($listen);
        $address = $server->getAddress()->toString();
        \assert($address !== '');

        $proxy = new self($server, $address);

        EventLoop::queue(static function () use (
            $proxy,
            $server,
            $upstream,
        ): void {
            while (($client = $server->accept()) !== null) {
                $nats = connect($upstream);
                $proxy->sockets[] = $client;
                $proxy->sockets[] = $nats;

                $future = async(static fn() => $proxy->forward($client, $nats, pausable: true));
                $future->ignore();
                $future = async(static fn() => $proxy->forward($nats, $client, pausable: false));
                $future->ignore();
            }
        });

        return $proxy;
    }

    /**
     * Holds back everything the clients send from now on, until resume().
     */
    public function pause(): void
    {
        $this->paused ??= new DeferredFuture();
    }

    /**
     * Holds back what the clients send starting from the given bytes, until resume().
     *
     * @param non-empty-string $needle
     * @return Future<void> completes once the bytes have been sent and held back
     */
    public function pauseBefore(string $needle): Future
    {
        /** @var DeferredFuture<void> $held */
        $held = new DeferredFuture();
        $this->pauseBefore = [$needle, $held];

        return $held->getFuture();
    }

    /**
     * Forwards what has been held back and everything the clients send next.
     */
    public function resume(): void
    {
        [$paused, $this->paused] = [$this->paused, null];
        $paused?->complete();
    }

    /**
     * Closes the connections passing through the proxy, as if the server went away; new ones are still accepted.
     */
    public function disconnect(): void
    {
        [$sockets, $this->sockets] = [$this->sockets, []];

        foreach ($sockets as $socket) {
            $socket->close();
        }
    }

    /**
     * Stops accepting and closes the connections: the server is down until a proxy is started on the same address again.
     */
    public function kill(): void
    {
        $this->server->close();
        $this->disconnect();
    }

    public function stop(): void
    {
        $this->resume();
        $this->server->close();
    }

    private function forward(Socket $from, Socket $to, bool $pausable): void
    {
        try {
            while (($chunk = $from->read()) !== null) {
                if ($pausable && $this->pauseBefore !== null && ($position = strpos($chunk, $this->pauseBefore[0])) !== false) {
                    [, $held] = $this->pauseBefore;
                    $this->pauseBefore = null;

                    $to->write(substr($chunk, 0, $position));
                    $chunk = substr($chunk, $position);

                    $this->pause();
                    $held->complete();
                }

                while ($pausable && ($paused = $this->paused) !== null) {
                    $paused->getFuture()->await();
                }

                $to->write($chunk);
            }
        } catch (\Throwable) {
        } finally {
            $from->close();
            $to->close();
        }
    }
}
