<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Amp\Socket\Certificate;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\SocketConnector;
use Thesis\Nats\Config;
use Thesis\Nats\Exception\ServerIsNotAvailable;
use Thesis\Nats\TlsConfig;

/**
 * @internal
 */
final readonly class SocketConnectionFactory implements ConnectionFactory
{
    private function __construct(
        private Config $config,
        private ConnectContext $context,
        private SocketConnector $connector,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $context = new ConnectContext()
            ->withConnectTimeout($config->connectionTimeout);

        if ($config->tcpNoDelay) {
            $context = $context->withTcpNoDelay();
        }

        // The default amp connector retries each url with an exponential backoff, which takes seconds
        // before an unavailable host is reported. The servers are iterated by the server pool instead, so connect only once.
        return new self($config, $context, new DnsSocketConnector());
    }

    public function connect(Server $server): Connection
    {
        // The credentials are read and a malformed seed is rejected before the socket is opened:
        // otherwise the server would be left waiting for CONNECT.
        $credentials = Credentials::fromConfig($this->config);

        // The socket starts unencrypted: the context is applied once the connection is upgraded to TLS,
        // which happens without TLS options too when the server requires it.
        $context = $this->context->withTlsContext(self::tlsContext($this->config->tls ?? new TlsConfig(), $server));

        try {
            $socket = $this->connector->connect($server->url, $context);
        } catch (\Throwable $e) {
            throw new ServerIsNotAvailable($e->getMessage(), previous: $e);
        }

        $connection = new SocketConnection($this->config, $socket, $credentials);

        try {
            $connection->startup();
        } catch (\Throwable $e) {
            // Nobody owns the connection yet: without closing it here a silent or misbehaving peer would keep the socket.
            $connection->close();

            throw $e;
        }

        return $connection;
    }

    private static function tlsContext(TlsConfig $config, Server $server): ClientTlsContext
    {
        $context = new ClientTlsContext($config->serverName ?? $server->tlsName())
            ->withCaFile($config->caFile);

        if (!$config->verifyPeer) {
            $context = $context
                ->withoutPeerVerification()
                ->withoutPeerNameVerification();
        }

        if ($config->certFile !== null) {
            $context = $context->withCertificate(new Certificate($config->certFile, $config->keyFile, $config->keyPassphrase));
        }

        return $context;
    }
}
