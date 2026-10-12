<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Amp\Socket\ConnectContext;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\Socket;
use Amp\Socket\SocketConnector;
use Thesis\Nats\Config;
use Thesis\Nats\Exception\ServerIsNotAvailable;

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
        $context = (new ConnectContext())
            ->withConnectTimeout($config->connectionTimeout);

        if ($config->tcpNoDelay) {
            $context = $context->withTcpNoDelay();
        }

        // The default amp connector retries each url with an exponential backoff, which takes seconds
        // before an unavailable host is reported. The hosts are iterated here instead, so connect only once.
        return new self($config, $context, new DnsSocketConnector());
    }

    public function connect(): Connection
    {
        $connection = new SocketConnection(
            $this->config,
            $this->createSocket(),
        );

        $connection->startup();

        return $connection;
    }

    private function createSocket(): Socket
    {
        $exceptions = [];

        foreach ($this->config->urls as $url) {
            try {
                return $this->connector->connect($url, $this->context);
            } catch (\Throwable $e) {
                $exceptions[$url] = $e->getMessage();
            }
        }

        throw new ServerIsNotAvailable(vsprintf('No available nats host: %s.', [
            implode('; ', array_map(
                static fn(string $url, string $exception): string => "{$url}: {$exception}",
                array_keys($exceptions),
                array_values($exceptions),
            )),
        ]));
    }
}
