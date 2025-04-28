<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Thesis\Nats\Internal\Lib;

/**
 * @api
 */
final class Config
{
    private const DEFAULT_URL = '127.0.0.1:4222';
    private const DEFAULT_HOST = '127.0.0.1';
    private const DEFAULT_PORT = 4222;
    private const DEFAULT_CONNECTION_TIMEOUT = 10;

    /** @var non-empty-string */
    public readonly string $version;

    /**
     * @param non-empty-list<non-empty-string> $urls
     * @param float $connectionTimeout in seconds
     * @param ?non-empty-string $user
     * @param ?non-empty-string $password
     */
    public function __construct(
        public readonly array $urls = [self::DEFAULT_URL],
        public readonly bool $verbose = true,
        public readonly bool $pedantic = false,
        public readonly float $connectionTimeout = self::DEFAULT_CONNECTION_TIMEOUT,
        public readonly ?string $user = null,
        public readonly ?string $password = null,
        public readonly bool $tcpNoDelay = true,
    ) {
        $this->version = Lib\version();
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * @param non-empty-string $uri
     * @throws \InvalidArgumentException
     */
    public static function fromURI(string $uri): self
    {
        $components = parse_url($uri);

        if ($components === false) {
            throw new \InvalidArgumentException("The uri '{$uri}' is invalid.");
        }

        $query = [];
        if (isset($components['query']) && $components['query'] !== '') {
            parse_str($components['query'], $query);
        }

        $connectionTimeout = self::DEFAULT_CONNECTION_TIMEOUT;
        if (isset($query['connection_timeout']) && is_numeric($query['connection_timeout']) && (int) $query['connection_timeout'] > 0) {
            /** @var positive-int $connectionTimeout */
            $connectionTimeout = (int) $query['connection_timeout'];
        }

        $tcpNoDelay = true;
        if (isset($query['tcp_nodelay'])) {
            $tcpNoDelay = filter_var($query['tcp_nodelay'], FILTER_VALIDATE_BOOL);
        }

        $verbose = true;
        if (isset($query['verbose'])) {
            $verbose = filter_var($query['verbose'], FILTER_VALIDATE_BOOL);
        }

        $pedantic = false;
        if (isset($query['pedantic'])) {
            $pedantic = filter_var($query['pedantic'], FILTER_VALIDATE_BOOL);
        }

        $port = self::DEFAULT_PORT;
        if (isset($components['port']) && $components['port'] > 0) {
            $port = $components['port'];
        }

        $urls = [];
        foreach (explode(',', $components['host'] ?? '') as $host) {
            $hostport = explode(':', $host);
            $urls[] = \sprintf('%s:%d', $hostport[0] ?: self::DEFAULT_HOST, (int) ($hostport[1] ?? $port));
        }

        $user = null;
        if (isset($components['user']) && $components['user'] !== '') {
            $user = $components['user'];
        }

        $password = null;
        if (isset($components['pass']) && $components['pass'] !== '') {
            $password = $components['pass'];
        }

        return new self(
            urls: $urls,
            verbose: $verbose,
            pedantic: $pedantic,
            connectionTimeout: $connectionTimeout,
            user: $user,
            password: $password,
            tcpNoDelay: $tcpNoDelay,
        );
    }

    /**
     * @param array{
     *     urls?: non-empty-list<non-empty-string>,
     *     user?: non-empty-string,
     *     password?: non-empty-string,
     *     verbose?: bool,
     *     pedantic?: bool,
     *     connection_timeout?: positive-int,
     *     tcp_nodelay?: bool,
     * } $options
     */
    public static function fromArray(array $options): self
    {
        return new self(
            urls: $options['urls'] ?? [self::DEFAULT_URL],
            verbose: $options['verbose'] ?? true,
            pedantic: $options['pedantic'] ?? false,
            connectionTimeout: $options['connection_timeout'] ?? self::DEFAULT_CONNECTION_TIMEOUT,
            user: $options['user'] ?? null,
            password: $options['password'] ?? null,
            tcpNoDelay: $options['tcp_nodelay'] ?? true,
        );
    }
}
