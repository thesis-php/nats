<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Hooks;

/**
 * @internal
 * @phpstan-type OnMessageCallback = callable(MessageReceived): void
 */
final class Provider
{
    /** @var list<OnMessageCallback> */
    private array $messageCallbacks = [];

    /**
     * @param OnMessageCallback $callback
     */
    public function onMessage(callable $callback): void
    {
        $this->messageCallbacks[] = $callback;
    }

    public function dispatch(MessageReceived $event): void
    {
        /** @phpstan-ignore instanceof.alwaysTrue */
        if ($event instanceof MessageReceived) {
            foreach ($this->messageCallbacks as $messageCallback) {
                $messageCallback($event);
            }
        }
    }
}
