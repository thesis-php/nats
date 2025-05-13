<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Connection;

use Amp\Socket\ConnectContext;
use Amp\Socket\Socket;
use Thesis\Nats\Config;
use Thesis\Nats\Exception\ServerIsNotAvailable;
use function Amp\Socket\connect;

/**
 * @internal
 */
final readonly class SocketConnectionFactory implements ConnectionFactory
{
    private function __construct(
        private Config $config,
        private ConnectContext $context,
    ) {}

    public static function fromConfig(Config $config): self
    {
        $context = (new ConnectContext())
            ->withConnectTimeout($config->connectionTimeout);

        if ($config->tcpNoDelay) {
            $context = $context->withTcpNoDelay();
        }

        return new self($config, $context);
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
                return connect($url, $this->context);
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
