<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Amp\DeferredFuture;
use Amp\Socket\Socket;
use Revolt\EventLoop;
use Thesis\Nats\Config;
use Thesis\Nats\Exception\ConnectionIsNotAvailable;
use Thesis\Nats\Internal\Hooks;
use Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
final class SocketConnection implements Connection
{
    private readonly Framer $framer;

    /** @var \SplQueue<DeferredFuture<Protocol\Frame>> */
    private readonly \SplQueue $queue;

    private readonly Hooks\Provider $hooks;

    private readonly ConnectionInfo $info;

    private bool $running = false;

    public function __construct(
        private readonly Config $config,
        private readonly Socket $socket,
    ) {
        $this->framer = new Framer($this->socket);
        $this->hooks = new Hooks\Provider();
        $this->info = new ConnectionInfo();

        /** @var \SplQueue<DeferredFuture<Protocol\Frame>> $queue */
        $queue = new \SplQueue();
        $this->queue = $queue;
    }

    /**
     * @throws \Throwable
     */
    public function startup(): void
    {
        $frame = $this->framer->readFrame() ?: throw new ConnectionIsNotAvailable();

        if (!$frame instanceof Protocol\ServerInfo) {
            throw new \UnexpectedValueException(
                \sprintf('An unexpected "%s" startup frame received.', $frame::class),
            );
        }

        $this->info->tune($frame);

        if (!$this->running) {
            $this->run();
        }

        $this->execute(new Protocol\Connect(
            verbose: $this->config->verbose,
            pedantic: $this->config->pedantic,
            tlsRequired: false,
            name: $this->config->name,
            version: $this->config->version,
            user: $this->config->user,
            pass: $this->config->password,
            noResponders: $this->config->noResponders,
            headers: $this->info->allowHeaders(),
        ));
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
                throw new \RuntimeException($frame->message);
            }
        }
    }

    public function hooks(): Hooks\Provider
    {
        return $this->hooks;
    }

    public function info(): ConnectionInfo
    {
        return $this->info;
    }

    public function close(): void
    {
        $this->running = false;
        $this->socket->close();
    }

    private function run(): void
    {
        $framer = $this->framer;
        $queue = $this->queue;
        $hooks = $this->hooks;
        $running = &$this->running;

        EventLoop::queue(static function () use ($framer, $queue, $hooks, &$running): void {
            while ($running) {
                try {
                    while (($frame = $framer->readFrame()) !== null) {
                        if (!$queue->isEmpty()) {
                            $deferred = $queue->shift();
                            $deferred->complete($frame);
                        }

                        if ($frame instanceof Protocol\Msg) {
                            $hooks->dispatch(new Hooks\MessageReceived(
                                subject: $frame->subject,
                                sid: $frame->sid,
                                replyTo: $frame->replyTo,
                                payload: $frame->message->payload,
                                headers: $frame->message->headers,
                            ));
                        }
                    }
                } catch (\Throwable $e) {
                    foreach ($queue as $deferred) {
                        $deferred->error($e);
                    }
                }
            }
        });

        $this->running = true;
    }
}
