<?php

declare(strict_types=1);

namespace Thesis\Nats\Micro\Internal;

/**
 * @internal
 */
final class RequestOutcome
{
    public private(set) bool $replied = false;

    public private(set) ?\Throwable $error = null;

    public function markReplied(): void
    {
        $this->replied = true;
    }

    public function fail(\Throwable $error): void
    {
        $this->error = $error;
    }
}
