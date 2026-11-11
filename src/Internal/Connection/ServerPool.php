<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

/**
 * The servers the client may connect to, the way nats.go keeps them: the configured ones (shuffled unless told otherwise)
 * and the ones the cluster announces in INFO (connect_urls). The first server is the current one; picking the next
 * moves it to the end, or drops it once it has failed too many reconnects in a row.
 *
 * @internal
 */
final class ServerPool
{
    /** @var list<Server> */
    private array $servers = [];

    /**
     * Every url ever seen, to tell a new server from one that has merely restarted.
     *
     * @var array<non-empty-string, true>
     */
    private array $known = [];

    /**
     * @param non-empty-list<non-empty-string> $urls
     * @param ?non-negative-int $maxReconnects the failed reconnects in a row after which a server is dropped, null for no limit
     */
    public function __construct(
        array $urls,
        private readonly bool $randomize,
        private readonly ?int $maxReconnects,
    ) {
        foreach ($urls as $url) {
            $this->add($url, implicit: false);
        }

        if ($this->randomize) {
            $this->shuffle(0);
        }
    }

    /**
     * @return list<Server> in the order to try them
     */
    public function servers(): array
    {
        return $this->servers;
    }

    public function count(): int
    {
        return \count($this->servers);
    }

    /**
     * Makes the server the current one, e.g. the one the initial connection was established with.
     */
    public function use(Server $server): void
    {
        $index = array_search($server, $this->servers, true);

        if ($index !== false && $index !== 0) {
            array_splice($this->servers, $index, 1);
            array_unshift($this->servers, $server);
        }
    }

    /**
     * Moves the current server to the end of the pool, or drops it once it has used up its reconnects.
     *
     * @return ?Server the server to try next, null if no server is left
     */
    public function next(): ?Server
    {
        $current = array_shift($this->servers);

        if ($current !== null && ($this->maxReconnects === null || $current->reconnects < $this->maxReconnects)) {
            $this->servers[] = $current;
        }

        return $this->servers[0] ?? null;
    }

    /**
     * Applies the servers a server of the cluster announces: adds the new ones, drops the announced ones it no longer
     * lists, keeps the configured ones and the current one.
     *
     * @param list<non-empty-string> $urls the `host:port` from connect_urls, an empty list means the server does not announce them
     * @return bool whether a server never seen before was added
     */
    public function discover(array $urls): bool
    {
        if ($urls === []) {
            return false;
        }

        $announced = array_fill_keys($urls, true);
        $current = $this->servers[0] ?? null;

        $this->servers = array_values(array_filter(
            $this->servers,
            static fn(Server $server): bool => !$server->implicit || $server === $current || isset($announced[$server->url]),
        ));

        foreach ($this->servers as $server) {
            unset($announced[$server->url]);
        }

        $new = false;

        foreach (array_keys($announced) as $url) {
            $new = $new || !isset($this->known[$url]);
            $this->add($url, implicit: true);
        }

        if ($new && $this->randomize) {
            // The current server keeps its place.
            $this->shuffle(1);
        }

        return $new;
    }

    /**
     * @return list<non-empty-string>
     */
    public function urls(): array
    {
        return array_map(static fn(Server $server): string => $server->url, $this->servers);
    }

    /**
     * @param non-empty-string $url
     */
    private function add(string $url, bool $implicit): void
    {
        $this->servers[] = new Server($url, $implicit);
        $this->known[$url] = true;
    }

    /**
     * @param non-negative-int $offset
     */
    private function shuffle(int $offset): void
    {
        $head = \array_slice($this->servers, 0, $offset);
        $tail = \array_slice($this->servers, $offset);
        shuffle($tail);

        $this->servers = [...$head, ...$tail];
    }
}
