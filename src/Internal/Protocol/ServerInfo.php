<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Protocol;

/**
 * @internal
 */
final class ServerInfo
{
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
}
