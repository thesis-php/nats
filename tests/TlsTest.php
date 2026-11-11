<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use PHPUnit\Framework\Attributes\CoversClass;
use Thesis\Nats\Exception\ConnectionIsNotAvailable;
use Thesis\Nats\Exception\ServerIsNotAvailable;
use Thesis\Nats\Exception\TlsHandshakeFailed;
use Thesis\Nats\Exception\TlsIsNotAvailable;
use Thesis\Nats\Internal\Connection\SocketConnection;
use Thesis\Nats\Internal\Connection\SocketConnectionFactory;
use Thesis\Nats\Internal\Id;
use function Amp\async;
use function Amp\Socket\connect;
use function Amp\Socket\listen;

/**
 * The servers of compose.yaml with the self-signed certificates of docker/nats/tls: nats-tls offers TLS, nats-mtls requires
 * a client certificate, nats-tls-first expects the TLS handshake before INFO.
 */
#[CoversClass(Client::class)]
#[CoversClass(Config::class)]
#[CoversClass(TlsConfig::class)]
#[CoversClass(SocketConnection::class)]
#[CoversClass(SocketConnectionFactory::class)]
final class TlsTest extends NatsTestCase
{
    private const string CERTIFICATES = __DIR__ . '/../docker/nats/tls';

    /** @var list<Client> */
    private array $clients = [];

    /** @var list<PausableProxy> */
    private array $proxies = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->stop();
        }

        foreach ($this->proxies as $proxy) {
            $proxy->kill();
        }

        parent::tearDown();
    }

    public function testConnectsOverTls(): void
    {
        $name = Id\generateUniqueId();
        $client = $this->tlsClient(self::env('THESIS_NATS_TLS_DSN') . '?tls_ca_file=' . self::ca() . "&client_name={$name}");

        $this->assertRoundTrip($client);
        self::assertNotNull($this->tlsVersion($name), 'The connection is not encrypted.');
    }

    public function testPlainClientStaysPlainWhenTlsIsOnlyOffered(): void
    {
        $name = Id\generateUniqueId();
        $client = $this->tlsClient(str_replace('tls://', 'nats://', self::env('THESIS_NATS_TLS_DSN')) . "?client_name={$name}");

        $this->assertRoundTrip($client);
        self::assertNull($this->tlsVersion($name));
    }

    public function testUntrustedServerCertificateIsRefused(): void
    {
        // Without the test CA the self-signed certificate is checked against the system authorities.
        $client = $this->tlsClient(self::env('THESIS_NATS_TLS_DSN'));

        $this->expectException(TlsHandshakeFailed::class);
        $this->expectExceptionMessageMatches('/certificate verify failed/');

        $client->connect();
    }

    public function testUnverifiedServerIsAcceptedWithoutVerification(): void
    {
        $client = $this->tlsClient(self::env('THESIS_NATS_TLS_DSN') . '?tls_verify=false');

        $this->assertRoundTrip($client);
    }

    public function testServerCertificateIsVerifiedAgainstTheServerName(): void
    {
        // The certificate is issued for the names of the servers, not for their address.
        $address = $this->address(self::env('THESIS_NATS_TLS_DSN'));

        $mismatched = $this->tlsClientWithConfig(new Config(urls: [$address], tls: new TlsConfig(caFile: self::ca())));

        try {
            $mismatched->connect();
            self::fail('The certificate of another name is accepted.');
        } catch (TlsHandshakeFailed $e) {
            self::assertStringContainsString('did not match expected name', $e->getMessage());
        }

        $named = $this->tlsClientWithConfig(new Config(urls: [$address], tls: new TlsConfig(caFile: self::ca(), serverName: 'nats-tls')));

        $this->assertRoundTrip($named);
    }

    public function testClientRequiringTlsRefusesServerWithoutIt(): void
    {
        $client = $this->tlsClientWithConfig(new Config(urls: Config::fromURI(self::env('THESIS_NATS_DSN'))->urls, tls: new TlsConfig()));

        $this->expectException(TlsIsNotAvailable::class);

        $client->connect();
    }

    public function testServerRequiringTlsGetsItWithoutTheOptions(): void
    {
        // The plain uri is upgraded, verifying the certificate as usual: the system authorities do not know the test CA.
        $client = $this->tlsClient(str_replace('tls://', 'nats://', self::env('THESIS_NATS_MTLS_DSN')));

        $this->expectException(TlsHandshakeFailed::class);
        $this->expectExceptionMessageMatches('/certificate verify failed/');

        $client->connect();
    }

    public function testClientCertificate(): void
    {
        $client = $this->tlsClient(self::env('THESIS_NATS_MTLS_DSN') . '?' . http_build_query([
            'tls_ca_file' => self::ca(),
            'tls_cert_file' => self::CERTIFICATES . '/client-cert.pem',
            'tls_key_file' => self::CERTIFICATES . '/client-key.pem',
        ]));

        $this->assertRoundTrip($client);
    }

    public function testEncryptedClientKey(): void
    {
        $client = $this->tlsClientWithConfig(new Config(
            urls: Config::fromURI(self::env('THESIS_NATS_MTLS_DSN'))->urls,
            tls: new TlsConfig(
                caFile: self::ca(),
                certFile: self::CERTIFICATES . '/client-cert.pem',
                keyFile: self::CERTIFICATES . '/client-key-encrypted.pem',
                keyPassphrase: 'Pswd1',
            ),
        ));

        $this->assertRoundTrip($client);
    }

    public function testServerVerifyingClientsRefusesClientWithoutCertificate(): void
    {
        $client = $this->tlsClientWithConfig(new Config(
            urls: Config::fromURI(self::env('THESIS_NATS_MTLS_DSN'))->urls,
            connectionTimeout: 2,
            allowReconnect: false,
            tls: new TlsConfig(caFile: self::ca()),
        ));

        $this->expectException(ConnectionIsNotAvailable::class);
        $this->expectExceptionMessageMatches('/client certificate/');

        $client->connect();
    }

    public function testHandshakeFirst(): void
    {
        $client = $this->tlsClient(self::env('THESIS_NATS_TLS_FIRST_DSN') . '?tls_handshake_first=true&tls_ca_file=' . self::ca());

        $this->assertRoundTrip($client);
    }

    public function testHandshakeFirstFailsWithServerSendingInfoFirst(): void
    {
        // The server answers the TLS handshake with its plain INFO.
        $client = $this->tlsClient(self::env('THESIS_NATS_TLS_DSN') . '?tls_handshake_first=true&tls_ca_file=' . self::ca());

        $this->expectException(TlsHandshakeFailed::class);

        $client->connect();
    }

    public function testServerExpectingHandshakeFirstDoesNotAnswerTheUsualOrder(): void
    {
        $client = $this->tlsClient(self::env('THESIS_NATS_TLS_FIRST_DSN') . '?connection_timeout=1&tls_ca_file=' . self::ca());

        $this->expectException(ServerIsNotAvailable::class);

        $client->connect();
    }

    public function testPlainDataBeforeTheHandshakeIsRefused(): void
    {
        // A man in the middle appends a plain PONG to INFO: taken for the encrypted answer to CONNECT,
        // it would make the client report a connection the server has not accepted.
        $upstream = $this->address(self::env('THESIS_NATS_TLS_DSN'));
        $server = listen('127.0.0.1:0');
        $address = $server->getAddress()->toString();
        self::assertNotSame('', $address);

        $future = async(static function () use ($server, $upstream): void {
            $client = $server->accept() ?? throw new \LogicException('The proxy is closed.');
            $nats = connect($upstream);

            $client->write(($nats->read() ?? '') . "PONG\r\n");

            $forward = async(static function () use ($client, $nats): void {
                while (($chunk = $client->read()) !== null) {
                    $nats->write($chunk);
                }

                $nats->close();
            });
            $forward->ignore();

            while (($chunk = $nats->read()) !== null) {
                $client->write($chunk);
            }

            $client->close();
        });
        $future->ignore();

        $client = $this->tlsClientWithConfig(new Config(
            urls: [$address],
            allowReconnect: false,
            tls: new TlsConfig(caFile: self::ca(), serverName: 'nats-tls'),
        ));

        try {
            $client->connect();
            self::fail('The plain data is accepted.');
        } catch (TlsHandshakeFailed $e) {
            self::assertStringContainsString('unexpected plain data', $e->getMessage());
        } finally {
            $server->close();
        }
    }

    public function testReconnectsOverTls(): void
    {
        $proxy = PausableProxy::start($this->address(self::env('THESIS_NATS_TLS_DSN')));
        $this->proxies[] = $proxy;

        $client = $this->tlsClientWithConfig(new Config(
            urls: [$proxy->address],
            reconnectWait: 0.1,
            tls: new TlsConfig(caFile: self::ca(), serverName: 'nats-tls'),
        ));

        /** @var DeferredFuture<void> $reconnected */
        $reconnected = new DeferredFuture();
        $client->onReconnect(static fn() => $reconnected->complete());

        $subject = Id\generateUniqueId();
        $deliveries = $client->subscribeIterator($subject);
        $client->ping();

        $proxy->disconnect();
        $reconnected->getFuture()->await(new TimeoutCancellation(5));

        // The subscription is restored on the new TLS connection.
        $client->publish($subject, new Message('after'));

        foreach ($deliveries as $delivery) {
            self::assertSame('after', $delivery->message->payload);
            $deliveries->stop();
        }
    }

    private function assertRoundTrip(Client $client): void
    {
        $subject = Id\generateUniqueId();
        $deliveries = $client->subscribeIterator($subject);
        $client->publish($subject, new Message('secret'));

        foreach ($deliveries as $delivery) {
            self::assertSame('secret', $delivery->message->payload);
            $deliveries->stop();
        }
    }

    /**
     * @param non-empty-string $uri
     */
    private function tlsClient(string $uri): Client
    {
        return $this->tlsClientWithConfig(Config::fromURI($uri));
    }

    private function tlsClientWithConfig(Config $config): Client
    {
        $client = new Client($config);
        $this->clients[] = $client;

        return $client;
    }

    /**
     * @param non-empty-string $dsn
     * @return non-empty-string the `ip:port` of the server
     */
    private function address(string $dsn): string
    {
        $url = Config::fromURI($dsn)->urls[0];
        $colon = (int) strrpos($url, ':');

        $address = gethostbyname(substr($url, 0, $colon)) . substr($url, $colon);
        self::assertNotSame('', $address);

        return $address;
    }

    /**
     * @param non-empty-string $name
     * @return ?non-empty-string the TLS version of the client connection to nats-tls, null for a plain one
     */
    private function tlsVersion(string $name): ?string
    {
        $connz = file_get_contents(self::env('THESIS_NATS_TLS_MONITORING_URL') . '/connz?limit=1024');
        self::assertIsString($connz);

        /** @var array{connections?: list<array{name?: string, tls_version?: non-empty-string}>} $connz */
        $connz = json_decode($connz, true, flags: JSON_THROW_ON_ERROR);

        foreach ($connz['connections'] ?? [] as $connection) {
            if (($connection['name'] ?? null) === $name) {
                return $connection['tls_version'] ?? null;
            }
        }

        self::fail("The client {$name} is not connected.");
    }

    /**
     * @return non-empty-string
     */
    private static function ca(): string
    {
        return self::CERTIFICATES . '/ca.pem';
    }

    /**
     * @param non-empty-string $name
     * @return non-empty-string
     */
    private static function env(string $name): string
    {
        $value = getenv($name);
        if (!\is_string($value) || $value === '') {
            self::markTestSkipped("{$name} must be set.");
        }

        return $value;
    }
}
