<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Hooks;

/**
 * @internal
 * @phpstan-type OnMessageCallback = \Closure(MessageReceived): void
 * @phpstan-type OnCloseCallback = \Closure(): void
 * @phpstan-type OnPingCallback = \Closure(): void
 * @phpstan-type OnPongCallback = \Closure(): void
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
     * @param OnCloseCallback $callback
     */
    public function onClose(\Closure $callback): void;
}
