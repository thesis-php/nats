<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Thesis\Nats\Internal\Protocol;

/**
 * What the server the client is connected to announces in INFO, the latest one: the server sends an update,
 * e.g. when the servers of the cluster change or when it enters the lame duck mode.
 * The fields old servers (before 2.2) and some proxies omit are null.
 *
 * @api
 */
final readonly class ServerInfo
{
    /**
     * @param non-empty-string $version the version of nats-server
     * @param ?non-empty-string $serverId the unique identifier of the server
     * @param ?non-empty-string $serverName the name of the server
     * @param ?non-empty-string $host the host the server listens on, as it advertises it
     * @param ?positive-int $port the port the server listens on
     * @param int $proto the protocol version of the server: 1 supports the echo option and the INFO updates
     * @param bool $headers whether the server supports message headers
     * @param ?positive-int $maxPayload the largest message (headers and payload) the server accepts, in bytes
     * @param bool $jetStream whether JetStream is enabled on the server
     * @param ?int $clientId the identifier of this client connection on the server, as in the monitoring and the server logs
     * @param ?non-empty-string $clientIp the address of this client as the server sees it
     * @param ?string $cluster the name of the cluster of the server
     * @param ?string $domain the JetStream domain of the server
     * @param list<non-empty-string> $connectUrls the `host:port` of the servers of the cluster the server announces
     * @param bool $lameDuckMode whether the server is going to shut down
     * @param ?bool $authRequired whether the server requires authentication
     * @param ?bool $tlsRequired whether the server requires TLS
     * @param ?bool $tlsAvailable whether the server accepts TLS without requiring it
     * @param ?bool $tlsVerify whether the server requires a client certificate
     * @param ?non-empty-string $go the version of Go the server was built with
     * @param ?string $gitCommit the commit the server was built from
     */
    public function __construct(
        public string $version,
        public ?string $serverId = null,
        public ?string $serverName = null,
        public ?string $host = null,
        public ?int $port = null,
        public int $proto = 0,
        public bool $headers = false,
        public ?int $maxPayload = null,
        public bool $jetStream = false,
        public ?int $clientId = null,
        public ?string $clientIp = null,
        public ?string $cluster = null,
        public ?string $domain = null,
        public array $connectUrls = [],
        public bool $lameDuckMode = false,
        public ?bool $authRequired = null,
        public ?bool $tlsRequired = null,
        public ?bool $tlsAvailable = null,
        public ?bool $tlsVerify = null,
        public ?string $go = null,
        public ?string $gitCommit = null,
    ) {}

    /**
     * @internal
     */
    public static function fromProtocol(Protocol\ServerInfo $info): self
    {
        return new self(
            version: $info->version,
            serverId: $info->serverId,
            serverName: $info->serverName,
            host: $info->host,
            port: $info->port,
            proto: $info->proto,
            headers: $info->headers,
            maxPayload: $info->maxPayload,
            jetStream: $info->jetstream ?? false,
            clientId: $info->clientId,
            clientIp: $info->clientIp,
            cluster: $info->cluster,
            domain: $info->domain,
            connectUrls: $info->connectUrls ?? [],
            lameDuckMode: $info->ldm ?? false,
            authRequired: $info->authRequired,
            tlsRequired: $info->tlsRequired,
            tlsAvailable: $info->tlsAvailable,
            tlsVerify: $info->tlsVerify,
            go: $info->go,
            gitCommit: $info->gitCommit,
        );
    }
}
