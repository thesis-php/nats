<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Thesis\Nats\Exception\InvalidSubject;
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
    private const int DEFAULT_MAX_RECONNECTS = 60;
    private const float DEFAULT_RECONNECT_WAIT = 2.0;
    private const float DEFAULT_RECONNECT_JITTER = 0.1;
    private const int DEFAULT_RECONNECT_BUFFER_SIZE = 8 * 1024 * 1024;
    private const string DEFAULT_INBOX_PREFIX = '_INBOX';
    private const float DEFAULT_DRAIN_TIMEOUT = 30.0;

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
     * @param ?non-empty-string $token the authorization token, exclusive with a user and a password
     * @param ?non-empty-string $credentialsFile the path to a user credentials file (`.creds`) holding both the JWT and the NKey seed,
     *                                           read on every connection attempt; exclusive with the other JWT and NKey options
     * @param ?non-empty-string $jwtFile the path to a file holding the user JWT, bare or decorated, read on every connection attempt; exclusive with $jwt
     * @param ?non-empty-string $nkeyFile the path to a file holding the NKey user seed, bare or decorated, read on every connection attempt; exclusive with $nkey
     * @param bool $allowReconnect whether the client reconnects once the connection is lost, restoring the subscriptions;
     *                             otherwise the subscriptions fail and the next call establishes a new connection
     * @param ?non-negative-int $maxReconnects the failed reconnects in a row after which a server is given up, null for no limit;
     *                                         once every server is given up, the client is closed
     * @param float $reconnectWait in seconds, the pause after every server has been tried
     * @param float $reconnectJitter in seconds, the upper bound of a random delay added to the pause, so that the clients do not reconnect at once
     * @param non-negative-int $reconnectBufferSize in bytes, the publishes buffered while reconnecting, 0 to fail them instead
     * @param bool $noRandomize whether to try the servers in the given order instead of a random one
     * @param ?TlsConfig $tls the TLS options, with which TLS is required; without them the connection is upgraded to TLS
     *                        only when the server requires it, verifying its certificate against the system authorities
     * @param bool $noEcho whether the server keeps the messages this client publishes from its own subscriptions
     * @param non-empty-string $inboxPrefix the prefix of the reply subjects of requests and of the other inboxes, see {@see Client::newInbox()}
     * @param float $drainTimeout in seconds, the bound of {@see Client::drain()}, after which the connection is closed anyway
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
        #[\SensitiveParameter]
        public ?string $token = null,
        public ?string $credentialsFile = null,
        public ?string $jwtFile = null,
        public ?string $nkeyFile = null,
        public bool $allowReconnect = true,
        public ?int $maxReconnects = self::DEFAULT_MAX_RECONNECTS,
        public float $reconnectWait = self::DEFAULT_RECONNECT_WAIT,
        public float $reconnectJitter = self::DEFAULT_RECONNECT_JITTER,
        public int $reconnectBufferSize = self::DEFAULT_RECONNECT_BUFFER_SIZE,
        public bool $noRandomize = false,
        public ?TlsConfig $tls = null,
        public bool $noEcho = false,
        public string $inboxPrefix = self::DEFAULT_INBOX_PREFIX,
        public float $drainTimeout = self::DEFAULT_DRAIN_TIMEOUT,
    ) {
        Validate::duration($connectionTimeout, 'connection timeout');

        // A negative limit would give up every server at once, while the uri means no limit by it: refused as a mistake,
        // null is the way to say no limit here.
        if ($maxReconnects !== null) {
            Validate::nonNegative($maxReconnects, 'max reconnects');
        }

        Validate::nonNegative($reconnectBufferSize, 'reconnect buffer size');

        if (!is_finite($reconnectWait) || $reconnectWait < 0) {
            throw new \InvalidArgumentException('The reconnect wait must be a finite non-negative number of seconds.');
        }

        if (!is_finite($reconnectJitter) || $reconnectJitter < 0) {
            throw new \InvalidArgumentException('The reconnect jitter must be a finite non-negative number of seconds.');
        }

        // nats-server itself refuses to have a token next to users, so both of them here are a mistake.
        if ($token !== null && ($user !== null || $password !== null)) {
            throw new \InvalidArgumentException('A token cannot be combined with a user and a password.');
        }

        if ($credentialsFile !== null && ($jwt !== null || $jwtFile !== null || $nkey !== null || $nkeyFile !== null)) {
            throw new \InvalidArgumentException('A credentials file already holds the JWT and the NKey seed: it cannot be combined with the other JWT and NKey options.');
        }

        if ($jwt !== null && $jwtFile !== null) {
            throw new \InvalidArgumentException('Only one of the JWT and the JWT file can be set.');
        }

        if ($nkey !== null && $nkeyFile !== null) {
            throw new \InvalidArgumentException('Only one of the NKey seed and the NKey file can be set.');
        }

        if ($requestTimeout !== null) {
            Validate::duration($requestTimeout, 'request timeout');
        }

        Validate::duration($drainTimeout, 'drain timeout');

        // The tokens of the inbox are appended to it, so it is a literal subject.
        try {
            Validate::publishSubject($inboxPrefix);
        } catch (InvalidSubject $e) {
            throw new \InvalidArgumentException("The inbox prefix is invalid: {$e->getMessage()}", previous: $e);
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
            // nats://token@host, as in nats.go; the token option takes precedence.
            token: $query->token ?? $parsed->token,
            credentialsFile: $query->credentialsFile,
            jwtFile: $query->jwtFile,
            nkeyFile: $query->nkeyFile,
            allowReconnect: $query->allowReconnect ?? true,
            maxReconnects: match (true) {
                $query->maxReconnects === null => self::DEFAULT_MAX_RECONNECTS,
                $query->maxReconnects < 0 => null,
                default => $query->maxReconnects,
            },
            reconnectWait: $query->reconnectWait ?? self::DEFAULT_RECONNECT_WAIT,
            reconnectJitter: $query->reconnectJitter ?? self::DEFAULT_RECONNECT_JITTER,
            reconnectBufferSize: $query->reconnectBufferSize ?? self::DEFAULT_RECONNECT_BUFFER_SIZE,
            noRandomize: $query->noRandomize ?? false,
            // tls:// or any of the TLS options requires TLS.
            tls: $parsed->tls || $query->hasTls() ? new TlsConfig(
                caFile: $query->tlsCaFile,
                certFile: $query->tlsCertFile,
                keyFile: $query->tlsKeyFile,
                keyPassphrase: $query->tlsKeyPassphrase,
                verifyPeer: $query->tlsVerify ?? true,
                serverName: $query->tlsServerName,
                handshakeFirst: $query->tlsHandshakeFirst ?? false,
            ) : null,
            noEcho: $query->noEcho ?? false,
            inboxPrefix: $query->inboxPrefix ?? self::DEFAULT_INBOX_PREFIX,
            drainTimeout: $query->drainTimeout ?? self::DEFAULT_DRAIN_TIMEOUT,
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
     *     token?: non-empty-string,
     *     credentials_file?: non-empty-string,
     *     jwt_file?: non-empty-string,
     *     nkey_file?: non-empty-string,
     *     allow_reconnect?: bool,
     *     max_reconnects?: ?non-negative-int,
     *     reconnect_wait?: float,
     *     reconnect_jitter?: float,
     *     reconnect_buffer_size?: non-negative-int,
     *     no_randomize?: bool,
     *     tls?: array{
     *         ca_file?: non-empty-string,
     *         cert_file?: non-empty-string,
     *         key_file?: non-empty-string,
     *         key_passphrase?: non-empty-string,
     *         verify_peer?: bool,
     *         server_name?: non-empty-string,
     *         handshake_first?: bool,
     *     },
     *     no_echo?: bool,
     *     inbox_prefix?: non-empty-string,
     *     drain_timeout?: float,
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
            token: $options['token'] ?? null,
            credentialsFile: $options['credentials_file'] ?? null,
            jwtFile: $options['jwt_file'] ?? null,
            nkeyFile: $options['nkey_file'] ?? null,
            allowReconnect: $options['allow_reconnect'] ?? true,
            maxReconnects: \array_key_exists('max_reconnects', $options) ? $options['max_reconnects'] : self::DEFAULT_MAX_RECONNECTS,
            reconnectWait: $options['reconnect_wait'] ?? self::DEFAULT_RECONNECT_WAIT,
            reconnectJitter: $options['reconnect_jitter'] ?? self::DEFAULT_RECONNECT_JITTER,
            reconnectBufferSize: $options['reconnect_buffer_size'] ?? self::DEFAULT_RECONNECT_BUFFER_SIZE,
            noRandomize: $options['no_randomize'] ?? false,
            tls: isset($options['tls']) ? new TlsConfig(
                caFile: $options['tls']['ca_file'] ?? null,
                certFile: $options['tls']['cert_file'] ?? null,
                keyFile: $options['tls']['key_file'] ?? null,
                keyPassphrase: $options['tls']['key_passphrase'] ?? null,
                verifyPeer: $options['tls']['verify_peer'] ?? true,
                serverName: $options['tls']['server_name'] ?? null,
                handshakeFirst: $options['tls']['handshake_first'] ?? false,
            ) : null,
            noEcho: $options['no_echo'] ?? false,
            inboxPrefix: $options['inbox_prefix'] ?? self::DEFAULT_INBOX_PREFIX,
            drainTimeout: $options['drain_timeout'] ?? self::DEFAULT_DRAIN_TIMEOUT,
        );
    }
}
