<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Hooks;

/**
 * The server announced servers of its cluster the client did not know about.
 *
 * @internal
 */
final readonly class ServersDiscovered
{
    /**
     * @param non-empty-list<non-empty-string> $urls the `host:port` of every known server
     */
    public function __construct(
        public array $urls,
    ) {}
}
