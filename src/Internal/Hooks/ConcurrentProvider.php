<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Hooks;

use Revolt\EventLoop;

/**
 * @internal
 * @phpstan-import-type OnMessageCallback from Provider
 * @phpstan-import-type OnPingCallback from Provider
 * @phpstan-import-type OnPongCallback from Provider
 * @phpstan-import-type OnCloseCallback from Provider
 */
final class ConcurrentProvider implements Provider
{
    /** @var list<OnMessageCallback> */
    private array $messageCallbacks = [];

    /** @var list<OnPingCallback> */
    private array $pingCallbacks = [];

    /** @var list<OnPongCallback> */
    private array $pongCallbacks = [];

    /** @var list<OnCloseCallback> */
    private array $closeCallbacks = [];

    public function onMessage(\Closure $callback): void
    {
        $this->messageCallbacks[] = $callback;
    }

    public function onClose(\Closure $callback): void
    {
        $this->closeCallbacks[] = $callback;
    }

    public function onPing(\Closure $callback): void
    {
        $this->pingCallbacks[] = $callback;
    }

    public function onPong(\Closure $callback): void
    {
        $this->pongCallbacks[] = $callback;
    }

    public function dispatch(MessageReceived|PingReceived|PongReceived|ConnectionClosed $event): void
    {
        $callbacks = match (true) {
            $event instanceof MessageReceived => $this->messageCallbacks,
            $event instanceof PingReceived => $this->pingCallbacks,
            $event instanceof PongReceived => $this->pongCallbacks,
            $event instanceof ConnectionClosed => $this->closeCallbacks,
        };

        foreach ($callbacks as $callback) {
            EventLoop::queue($callback, $event);
        }
    }
}
