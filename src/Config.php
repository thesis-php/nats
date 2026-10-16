<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Thesis\Nats\Internal\Connection\Uri;
use Thesis\Nats\Internal\Validation\Validate;

/**
 * @api
 */
final readonly class Config
{
    private const string DEFAULT_URL = '127.0.0.1:4222';
    private const float DEFAULT_CONNECTION_TIMEOUT = 10.0;
    private const int DEFAULT_PING_INTERVAL = 10000;
    private const int DEFAULT_MAX_PINGS = 5;
    private const string DEFAULT_CLIENT_NAME = 'thesis/nats';

    /**
     * @var non-empty-string
     * @deprecated will be removed in 0.5.0
     */
    public string $version;

    /**
     * @param non-empty-list<non-empty-string> $urls
     * @param float $connectionTimeout in seconds, bounds both the TCP connect and the handshake (INFO, CONNECT, PING/PONG)
     * @param ?non-empty-string $user
     * @param ?non-empty-string $password
     * @param ?non-empty-string $jwt JWT token for authentication
     * @param ?non-empty-string $nkey NKey user seed ("SU...") for signature authentication: the public key derived from it is sent to the server, the seed itself never leaves the client
     * @param ?positive-int $ping in milliseconds
     * @param positive-int $maxPings the maximum number of pings that we have not received a response to, after which the connection to the server will be closed
     * @param ?non-empty-string $jetStreamDomain
     * @param non-empty-string $clientName
     * @param ?float $requestTimeout in seconds, the default timeout of {@see Client::request()} and {@see Client::requestMany()}, null for none
     * @throws \InvalidArgumentException
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
        #[\SensitiveParameter]
        public ?string $jwt = null,
        #[\SensitiveParameter]
        public ?string $nkey = null,
        public bool $tcpNoDelay = true,
        public bool $noResponders = false,
        public ?int $ping = self::DEFAULT_PING_INTERVAL,
        public int $maxPings = self::DEFAULT_MAX_PINGS,
        public ?string $jetStreamDomain = null,
        public string $clientName = self::DEFAULT_CLIENT_NAME,
        public ?float $requestTimeout = null,
    ) {
        Validate::duration($connectionTimeout, 'connection timeout');

        if ($requestTimeout !== null) {
            Validate::duration($requestTimeout, 'request timeout');
        }

        $this->version = '0.1.x';
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
        $parsed = Uri::parse($uri);
        $query = $parsed->query;

        return new self(
            urls: $parsed->urls,
            verbose: $query->verbose ?? false,
            pedantic: $query->pedantic ?? false,
            connectionTimeout: $query->connectionTimeout ?? self::DEFAULT_CONNECTION_TIMEOUT,
            user: $parsed->user,
            password: $parsed->password,
            jwt: $query->jwt,
            nkey: $query->nkey,
            tcpNoDelay: $query->tcpNoDelay ?? true,
            noResponders: $query->noResponders ?? false,
            ping: match (true) {
                $query->ping === -1 => null,
                $query->ping !== null && $query->ping > 0 => $query->ping,
                default => self::DEFAULT_PING_INTERVAL,
            },
            maxPings: $query->maxPings ?? self::DEFAULT_MAX_PINGS,
            jetStreamDomain: $query->jetStreamDomain,
            clientName: $query->clientName ?? self::DEFAULT_CLIENT_NAME,
            requestTimeout: $query->requestTimeout,
        );
    }

    /**
     * @param array{
     *     urls?: non-empty-list<non-empty-string>,
     *     user?: non-empty-string,
     *     password?: non-empty-string,
     *     jwt?: non-empty-string,
     *     nkey?: non-empty-string,
     *     verbose?: bool,
     *     pedantic?: bool,
     *     connection_timeout?: float,
     *     tcp_nodelay?: bool,
     *     no_responders?: bool,
     *     ping?: positive-int,
     *     max_pings?: positive-int,
     *     jetstream_domain?: non-empty-string,
     *     client_name?: non-empty-string,
     *     request_timeout?: float,
     * } $options
     * @throws \InvalidArgumentException
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
            jwt: $options['jwt'] ?? null,
            nkey: $options['nkey'] ?? null,
            tcpNoDelay: $options['tcp_nodelay'] ?? true,
            noResponders: $options['no_responders'] ?? false,
            ping: $options['ping'] ?? self::DEFAULT_PING_INTERVAL,
            maxPings: $options['max_pings'] ?? self::DEFAULT_MAX_PINGS,
            jetStreamDomain: $options['jetstream_domain'] ?? null,
            clientName: $options['client_name'] ?? self::DEFAULT_CLIENT_NAME,
            requestTimeout: $options['request_timeout'] ?? null,
        );
    }
}
