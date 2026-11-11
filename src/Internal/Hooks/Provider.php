<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Hooks;

/**
 * @internal
 * @phpstan-type OnMessageCallback = \Closure(MessageReceived): void
 * @phpstan-type OnCloseCallback = \Closure(ConnectionClosed): void
 * @phpstan-type OnPingCallback = \Closure(): void
 * @phpstan-type OnPongCallback = \Closure(): void
 * @phpstan-type OnErrorCallback = \Closure(ErrorReceived): void
 * @phpstan-type OnInfoCallback = \Closure(InfoReceived): void
 * @phpstan-type OnDisconnectCallback = \Closure(Disconnected): void
 * @phpstan-type OnReconnectCallback = \Closure(): void
 * @phpstan-type OnServersDiscoveredCallback = \Closure(ServersDiscovered): void
 * @phpstan-type OnLameDuckModeCallback = \Closure(): void
 */
interface Provider
{
    /**
     * @param OnMessageCallback $callback
     */
    public function onMessage(\Closure $callback): void;

    /**
     * @param OnPingCallback $callback
     */
    public function onPing(\Closure $callback): void;

    /**
     * @param OnPongCallback $callback
     */
    public function onPong(\Closure $callback): void;

    /**
     * @param OnErrorCallback $callback
     */
    public function onError(\Closure $callback): void;

    /**
     * @param OnCloseCallback $callback
     */
    public function onClose(\Closure $callback): void;

    /**
     * @param OnInfoCallback $callback
     */
    public function onInfo(\Closure $callback): void;

    /**
     * @param OnDisconnectCallback $callback
     */
    public function onDisconnect(\Closure $callback): void;

    /**
     * @param OnReconnectCallback $callback
     */
    public function onReconnect(\Closure $callback): void;

    /**
     * @param OnServersDiscoveredCallback $callback
     */
    public function onServersDiscovered(\Closure $callback): void;

    /**
     * @param OnLameDuckModeCallback $callback
     */
    public function onLameDuckMode(\Closure $callback): void;
}
