<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

/**
 * A server of the pool and its reconnect statistics.
 *
 * @internal
 */
final class Server
{
    /** @var non-negative-int the reconnect attempts since the client was last connected to this server */
    public private(set) int $reconnects = 0;

    /** @var ?class-string<\Throwable> the error the last attempt failed with */
    public private(set) ?string $lastError = null;

    /**
     * @param non-empty-string $url `host:port`
     * @param bool $implicit whether the server was announced by the cluster rather than configured
     * @param ?non-empty-string $tlsName the name to verify the server certificate against instead of the host:
     *                                   a server announced by its IP address is verified against the name of the one that announced it
     */
    public function __construct(
        public readonly string $url,
        public readonly bool $implicit,
        public readonly ?string $tlsName = null,
    ) {}

    /**
     * @return non-empty-string the host without the port, an IPv6 address without the brackets
     */
    public function host(): string
    {
        $colon = strrpos($this->url, ':');
        $host = $colon !== false ? substr($this->url, 0, $colon) : $this->url;
        $host = trim($host, '[]');

        return $host !== '' ? $host : $this->url;
    }

    /**
     * @return non-empty-string the name the server certificate is expected to have
     */
    public function tlsName(): string
    {
        return $this->tlsName ?? $this->host();
    }

    public function hasIpAddress(): bool
    {
        return filter_var($this->host(), FILTER_VALIDATE_IP) !== false;
    }

    /**
     * @return bool whether the attempt failed with the same kind of error as the previous one
     */
    public function failed(\Throwable $e): bool
    {
        ++$this->reconnects;
        $repeated = $this->lastError === $e::class;
        $this->lastError = $e::class;

        return $repeated;
    }

    public function connected(): void
    {
        $this->reconnects = 0;
        $this->lastError = null;
    }
}
