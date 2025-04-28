<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
final class ServerInfo implements Frame
{
    /**
     * @param non-empty-string $json
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     * @throws \JsonException
     */
    public static function fromJson(string $json): self
    {
        $payload = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);

        if (!\is_array($payload) || array_is_list($payload)) {
            throw new \UnexpectedValueException("'info' must be an non-empty associative array.");
        }

        /** @var non-empty-array<non-empty-string, mixed> $payload */
        return self::fromArray($payload);
    }

    /**
     * @param non-empty-array<non-empty-string, mixed> $payload
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            serverId: self::assert($payload, 'server_id', self::nonEmptyString(...)),
            serverName: self::assert($payload, 'server_name', self::nonEmptyString(...)),
            version: self::assert($payload, 'version', self::nonEmptyString(...)),
            go: self::assert($payload, 'go', self::nonEmptyString(...)),
            host: self::assert($payload, 'host', self::nonEmptyString(...)),
            port: self::assert($payload, 'port', self::positiveInt(...)),
            headers: self::assert($payload, 'headers', self::bool(...)),
            maxPayload: self::assert($payload, 'max_payload', self::positiveInt(...)),
            proto: self::assert($payload, 'proto', self::int(...)),
            clientId: self::nullable($payload, 'client_id', self::int(...)),
            authRequired: self::nullable($payload, 'auth_required', self::bool(...)),
            tlsRequired: self::nullable($payload, 'tls_required', self::bool(...)),
            tlsVerify: self::nullable($payload, 'tls_verify', self::bool(...)),
            tlsAvailable: self::nullable($payload, 'tls_available', self::bool(...)),
            connectUrls: self::list($payload, 'connect_urls', self::nonEmptyString(...)),
            wsConnectUrls: self::list($payload, 'ws_connect_urls', self::nonEmptyString(...)),
            ldm: self::nullable($payload, 'ldm', self::bool(...)),
            gitCommit: self::nullable($payload, 'git_commit', self::string(...)),
            jetstream: self::nullable($payload, 'jetstream', self::bool(...)),
            ip: self::nullable($payload, 'ip', self::nonEmptyString(...)),
            clientIp: self::nullable($payload, 'client_ip', self::nonEmptyString(...)),
            nonce: self::nullable($payload, 'nonce', self::string(...)),
            cluster: self::nullable($payload, 'cluster', self::string(...)),
            domain: self::nullable($payload, 'domain', self::string(...)),
        );
    }

    /**
     * @param non-empty-string $serverId the unique identifier of the NATS server
     * @param non-empty-string $serverName the name of the NATS server
     * @param non-empty-string $version the version of NATS
     * @param non-empty-string $go the version of golang the NATS server was built with
     * @param non-empty-string $host the IP address used to start the NATS server, by default this will be 0.0.0.0 and can be configured with -client_advertise host:port
     * @param positive-int $port the port number the NATS server is configured to listen on
     * @param bool $headers whether the server supports headers
     * @param positive-int $maxPayload maximum payload size, in bytes, that the server will accept from the client
     * @param int $proto an integer indicating the protocol version of the server. The server version 1.2.0 sets this to 1 to indicate that it supports the "Echo" feature
     * @param ?int $clientId the internal client identifier in the server. This can be used to filter client connections in monitoring, correlate with error logs
     * @param ?bool $authRequired if this is true, then the client should try to authenticate upon connect
     * @param ?bool $tlsRequired if this is true, then the client must perform the TLS/1.2 handshake. Note, this used to be ssl_required and has been updated along with the protocol from SSL to TLS
     * @param ?bool $tlsVerify if this is true, the client must provide a valid certificate during the TLS handshake
     * @param ?bool $tlsAvailable if this is true, the client can provide a valid certificate during the TLS handshake
     * @param ?list<non-empty-string> $connectUrls list of server urls that a client can connect to
     * @param ?list<non-empty-string> $wsConnectUrls list of server urls that a websocket client can connect to
     * @param ?bool $ldm if the server supports Lame Duck Mode notifications, and the current server has transitioned to lame duck, ldm will be set to true
     * @param ?string $gitCommit the git hash at which the NATS server was built
     * @param ?bool $jetstream whether the server supports JetStream
     * @param ?non-empty-string $ip the IP of the server
     * @param ?non-empty-string $clientIp the IP of the client
     * @param ?string $nonce the nonce for use in CONNECT
     * @param ?string $cluster the name of the cluster
     * @param ?string $domain the configured NATS domain of the server
     */
    public function __construct(
        public readonly string $serverId,
        public readonly string $serverName,
        public readonly string $version,
        public readonly string $go,
        public readonly string $host,
        public readonly int $port,
        public readonly bool $headers,
        public readonly int $maxPayload,
        public readonly int $proto,
        public readonly ?int $clientId = null,
        public readonly ?bool $authRequired = null,
        public readonly ?bool $tlsRequired = null,
        public readonly ?bool $tlsVerify = null,
        public readonly ?bool $tlsAvailable = null,
        public readonly ?array $connectUrls = null,
        public readonly ?array $wsConnectUrls = null,
        public readonly ?bool $ldm = null,
        public readonly ?string $gitCommit = null,
        public readonly ?bool $jetstream = null,
        public readonly ?string $ip = null,
        public readonly ?string $clientIp = null,
        public readonly ?string $nonce = null,
        public readonly ?string $cluster = null,
        public readonly ?string $domain = null,
    ) {}

    /**
     * @template T
     * @param non-empty-array<non-empty-string, mixed> $payload
     * @param non-empty-string $key
     * @param callable(non-empty-string, mixed): T $assert
     * @return ?T
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     */
    private static function nullable(array $payload, string $key, callable $assert): mixed
    {
        if (!isset($payload[$key])) {
            return null;
        }

        return self::assert($payload, $key, $assert);
    }

    /**
     * @template T
     * @param non-empty-array<non-empty-string, mixed> $payload
     * @param non-empty-string $key
     * @param callable(non-empty-string, mixed): T $assert
     * @return ?list<T>
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     */
    private static function list(array $payload, string $key, callable $assert): ?array
    {
        if (!isset($payload[$key])) {
            return null;
        }

        $values = $payload[$key];

        if (!\is_array($values) || !array_is_list($values)) {
            throw new \UnexpectedValueException("The 'info' key '{$key}' must be a list.");
        }

        return array_map(static fn(mixed $value): mixed => $assert($key, $value), $values);
    }

    /**
     * @param non-empty-string $key
     * @return non-empty-string
     * @throws \UnexpectedValueException
     * @throws \InvalidArgumentException
     */
    private static function nonEmptyString(string $key, mixed $value): string
    {
        $value = self::string($key, $value);

        if ($value === '') {
            throw new \InvalidArgumentException("The 'info' key '{$key}' must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @param non-empty-string $key
     * @throws \UnexpectedValueException
     */
    private static function string(string $key, mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException("The 'info' key '{$key}' must be a string.");
        }

        return $value;
    }

    /**
     * @param non-empty-string $key
     * @return positive-int
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     */
    private static function positiveInt(string $key, mixed $value): int
    {
        $value = self::int($key, $value);

        if ($value <= 0) {
            throw new \InvalidArgumentException("The 'info' key '{$key}' must be a positive integer.");
        }

        return $value;
    }

    /**
     * @param non-empty-string $key
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     */
    private static function int(string $key, mixed $value): int
    {
        if (!\is_int($value)) {
            throw new \UnexpectedValueException("The 'info' key '{$key}' must be an integer.");
        }

        return $value;
    }

    /**
     * @param non-empty-string $key
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     */
    private static function bool(string $key, mixed $value): bool
    {
        if (!\is_bool($value)) {
            throw new \UnexpectedValueException("The 'info' key '{$key}' must be a boolean.");
        }

        return $value;
    }

    /**
     * @template T
     * @param non-empty-array<non-empty-string, mixed> $payload
     * @param non-empty-string $key
     * @param callable(non-empty-string, mixed): T $assert
     * @return T
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     */
    private static function assert(array $payload, string $key, callable $assert): mixed
    {
        if (!isset($payload[$key])) {
            throw new \InvalidArgumentException("'info' must contain the key '{$key}'.");
        }

        return $assert($key, $payload[$key]);
    }
}
