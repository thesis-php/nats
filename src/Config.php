<?php

declare(strict_types=1);

namespace Thesis\Nats;

/**
 * @api
 */
final readonly class Config
{
    private const string DEFAULT_URL = '127.0.0.1:4222';
    private const string DEFAULT_HOST = '127.0.0.1';
    private const int DEFAULT_PORT = 4222;
    private const int DEFAULT_CONNECTION_TIMEOUT = 10;
    private const int DEFAULT_PING_INTERVAL = 10000;
    private const int DEFAULT_MAX_PINGS = 5;

    /** @var non-empty-string */
    public string $version;

    /**
     * @param non-empty-list<non-empty-string> $urls
     * @param float $connectionTimeout in seconds
     * @param ?non-empty-string $user
     * @param ?non-empty-string $password
     * @param ?positive-int $ping in milliseconds
     * @param positive-int $maxPings the maximum number of pings that we have not received a response to, after which the connection to the server will be closed
     * @param ?non-empty-string $jetStreamDomain
     */
    public function __construct(
        public array $urls = [self::DEFAULT_URL],
        public bool $verbose = false,
        public bool $pedantic = false,
        public float $connectionTimeout = self::DEFAULT_CONNECTION_TIMEOUT,
        #[\SensitiveParameter]
        public ?string $user = null,
        #[\SensitiveParameter]
        public ?string $password = null,
        public bool $tcpNoDelay = true,
        public bool $noResponders = false,
        public ?int $ping = self::DEFAULT_PING_INTERVAL,
        public int $maxPings = self::DEFAULT_MAX_PINGS,
        public ?string $jetStreamDomain = null,
    ) {
        $this->version = '0.1.x'; // TODO: replace with actual version.
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * @param non-empty-string $uri
     * @throws \InvalidArgumentException
     */
    public static function fromURI(#[\SensitiveParameter] string $uri): self
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

        $verbose = false;
        if (isset($query['verbose'])) {
            $verbose = filter_var($query['verbose'], FILTER_VALIDATE_BOOL);
        }

        $pedantic = false;
        if (isset($query['pedantic'])) {
            $pedantic = filter_var($query['pedantic'], FILTER_VALIDATE_BOOL);
        }

        $noResponders = false;
        if (isset($query['no_responders'])) {
            $noResponders = filter_var($query['no_responders'], FILTER_VALIDATE_BOOL);
        }

        $ping = self::DEFAULT_PING_INTERVAL;
        if (isset($query['ping']) && is_numeric($query['ping'])) {
            /** @var ?positive-int $ping */
            $ping = match ($interval = (int) $query['ping']) {
                -1 => null,
                default => $interval < 0 ? self::DEFAULT_PING_INTERVAL : $interval,
            };
        }

        $maxPings = self::DEFAULT_MAX_PINGS;
        if (isset($query['max_pings']) && is_numeric($query['max_pings']) && (int) $query['max_pings'] > 0) {
            /** @var positive-int $maxPings */
            $maxPings = (int) $query['max_pings'];
        }

        $jetStreamDomain = null;
        if (isset($query['jetstream_domain']) && \is_string($query['jetstream_domain']) && $query['jetstream_domain'] !== '') {
            $jetStreamDomain = $query['jetstream_domain'];
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
            noResponders: $noResponders,
            ping: $ping,
            maxPings: $maxPings,
            jetStreamDomain: $jetStreamDomain,
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
     *     no_responders?: bool,
     *     ping?: positive-int,
     *     max_pings?: positive-int,
     *     jetstream_domain?: non-empty-string,
     * } $options
     */
    public static function fromArray(#[\SensitiveParameter] array $options): self
    {
        return new self(
            urls: $options['urls'] ?? [self::DEFAULT_URL],
            verbose: $options['verbose'] ?? false,
            pedantic: $options['pedantic'] ?? false,
            connectionTimeout: $options['connection_timeout'] ?? self::DEFAULT_CONNECTION_TIMEOUT,
            user: $options['user'] ?? null,
            password: $options['password'] ?? null,
            tcpNoDelay: $options['tcp_nodelay'] ?? true,
            noResponders: $options['no_responders'] ?? false,
            ping: $options['ping'] ?? self::DEFAULT_PING_INTERVAL,
            maxPings: $options['max_pings'] ?? self::DEFAULT_MAX_PINGS,
            jetStreamDomain: $options['jetstream_domain'] ?? null,
        );
    }
}
