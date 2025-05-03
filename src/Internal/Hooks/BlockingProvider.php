<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Hooks;

/**
 * @internal
 * @phpstan-import-type OnMessageCallback from Provider
 * @phpstan-import-type OnPingCallback from Provider
 * @phpstan-import-type OnPongCallback from Provider
 * @phpstan-import-type OnCloseCallback from Provider
 */
final class BlockingProvider implements Provider
{
    /** @var list<OnMessageCallback> */
    private array $messageCallbacks = [];

    /** @var list<OnPingCallback> */
    private array $pingCallbacks = [];

    /** @var list<OnPongCallback> */
    private array $pongCallbacks = [];

    /** @var list<OnCloseCallback> */
    private array $closeCallbacks = [];

    public function onMessage(callable $callback): void
    {
        $this->messageCallbacks[] = $callback;
    }

    public function onClose(callable $callback): void
    {
        $this->closeCallbacks[] = $callback;
    }

    public function onPing(callable $callback): void
    {
        $this->pingCallbacks[] = $callback;
    }

    public function onPong(callable $callback): void
    {
        $this->pongCallbacks[] = $callback;
    }

    public function dispatch(MessageReceived|PingReceived|PongReceived|ConnectionClosed $event): void
    {
        if ($event instanceof MessageReceived) {
            foreach ($this->messageCallbacks as $messageCallback) {
                $messageCallback($event);
            }
        } else {
            $callbacks = match (true) {
                $event instanceof PingReceived => $this->pingCallbacks,
                $event instanceof PongReceived => $this->pongCallbacks,
                $event instanceof ConnectionClosed => $this->closeCallbacks,
            };

            foreach ($callbacks as $callback) {
                $callback();
            }
        }
    }
}
