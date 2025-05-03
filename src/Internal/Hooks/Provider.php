<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Hooks;

/**
 * @internal
 * @phpstan-type OnMessageCallback = callable(MessageReceived): void
 * @phpstan-type OnCloseCallback = callable(): void
 * @phpstan-type OnPingCallback = callable(): void
 * @phpstan-type OnPongCallback = callable(): void
 */
interface Provider
{
    /**
     * @param OnMessageCallback $callback
     */
    public function onMessage(callable $callback): void;

    /**
     * @param OnPingCallback $callback
     */
    public function onPing(callable $callback): void;

    /**
     * @param OnPongCallback $callback
     */
    public function onPong(callable $callback): void;

    /**
     * @param OnCloseCallback $callback
     */
    public function onClose(callable $callback): void;
}
