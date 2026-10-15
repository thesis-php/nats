<?php

declare(strict_types=1);

namespace Thesis\Nats\JetStream\Internal;

/**
 * A push consumer handler that wants to know when the server has nothing more to deliver.
 *
 * @internal
 */
interface IdleListener
{
    /**
     * Called on an idle heartbeat: the server sends it only when it has not delivered anything for the heartbeat interval
     * and does not wait for flow control, so every message before it has been handled.
     */
    public function idle(): void;
}
