<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Amp\Socket\Socket;
use Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
final class Writer
{
    /** @var Protocol\Frame[] */
    private array $frames = [];

    public function __construct(
        private readonly Socket $socket,
    ) {}

    /**
     * @param Protocol\Frame[] $frames
     */
    public function write(array $frames): void
    {
        $this->frames = [...$this->frames, ...$frames];
    }

    public function flush(): void
    {
        [$frames, $this->frames] = [$this->frames, []];

        if (\count($frames) > 0) {
            $this->socket->write(
                implode(
                    '',
                    array_map(
                        static fn(Protocol\Frame $frame): string => $frame->encode(),
                        $frames,
                    ),
                ),
            );
        }
    }
}
