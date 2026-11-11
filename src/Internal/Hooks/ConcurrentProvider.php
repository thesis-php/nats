<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Hooks;

use Revolt\EventLoop;

/**
 * @internal
 * @phpstan-import-type OnMessageCallback from Provider
 * @phpstan-import-type OnPingCallback from Provider
 * @phpstan-import-type OnPongCallback from Provider
 * @phpstan-import-type OnErrorCallback from Provider
 * @phpstan-import-type OnCloseCallback from Provider
 * @phpstan-import-type OnInfoCallback from Provider
 * @phpstan-import-type OnDisconnectCallback from Provider
 * @phpstan-import-type OnReconnectCallback from Provider
 * @phpstan-import-type OnServersDiscoveredCallback from Provider
 * @phpstan-import-type OnLameDuckModeCallback from Provider
 */
final class ConcurrentProvider implements Provider
{
    /** @var list<OnMessageCallback> */
    private array $messageCallbacks = [];

    /** @var list<OnPingCallback> */
    private array $pingCallbacks = [];

    /** @var list<OnPongCallback> */
    private array $pongCallbacks = [];

    /** @var list<OnErrorCallback> */
    private array $errorCallbacks = [];

    /** @var list<OnCloseCallback> */
    private array $closeCallbacks = [];

    /** @var list<OnInfoCallback> */
    private array $infoCallbacks = [];

    /** @var list<OnDisconnectCallback> */
    private array $disconnectCallbacks = [];

    /** @var list<OnReconnectCallback> */
    private array $reconnectCallbacks = [];

    /** @var list<OnServersDiscoveredCallback> */
    private array $serversDiscoveredCallbacks = [];

    /** @var list<OnLameDuckModeCallback> */
    private array $lameDuckModeCallbacks = [];

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

    public function onError(\Closure $callback): void
    {
        $this->errorCallbacks[] = $callback;
    }

    public function onInfo(\Closure $callback): void
    {
        $this->infoCallbacks[] = $callback;
    }

    public function onDisconnect(\Closure $callback): void
    {
        $this->disconnectCallbacks[] = $callback;
    }

    public function onReconnect(\Closure $callback): void
    {
        $this->reconnectCallbacks[] = $callback;
    }

    public function onServersDiscovered(\Closure $callback): void
    {
        $this->serversDiscoveredCallbacks[] = $callback;
    }

    public function onLameDuckMode(\Closure $callback): void
    {
        $this->lameDuckModeCallbacks[] = $callback;
    }

    public function dispatch(
        MessageReceived|PingReceived|PongReceived|ErrorReceived|ConnectionClosed|InfoReceived|Disconnected|Reconnected|ServersDiscovered|LameDuckModeEntered $event,
    ): void {
        $callbacks = match (true) {
            $event instanceof MessageReceived => $this->messageCallbacks,
            $event instanceof PingReceived => $this->pingCallbacks,
            $event instanceof PongReceived => $this->pongCallbacks,
            $event instanceof ErrorReceived => $this->errorCallbacks,
            $event instanceof ConnectionClosed => $this->closeCallbacks,
            $event instanceof InfoReceived => $this->infoCallbacks,
            $event instanceof Disconnected => $this->disconnectCallbacks,
            $event instanceof Reconnected => $this->reconnectCallbacks,
            $event instanceof ServersDiscovered => $this->serversDiscoveredCallbacks,
            $event instanceof LameDuckModeEntered => $this->lameDuckModeCallbacks, // @phpstan-ignore instanceof.alwaysTrue
        };

        foreach ($callbacks as $callback) {
            EventLoop::queue($callback, $event);
        }
    }
}
