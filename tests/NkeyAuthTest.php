<?php

declare(strict_types=1);

namespace Thesis\Nats;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\TestWith;
use Thesis\Nats\Exception\AuthorizationViolation;
use Thesis\Nats\Exception\InvalidNKey;
use Thesis\Nats\Internal\Connection\SocketConnection;
use Thesis\Nats\Internal\Nkey\KeyPair;

/**
 * Pure NKey authentication (without JWT): the server knows only the public key of the user,
 * the client must send it in CONNECT together with the nonce signed by the seed.
 */
#[CoversClass(Client::class)]
#[CoversClass(SocketConnection::class)]
#[CoversClass(KeyPair::class)]
#[RequiresPhpExtension('sodium')]
final class NkeyAuthTest extends NatsTestCase
{
    /** The seed of the user with the public key UAANT3HVY2YJTAZ64BOYWU36YUE77A26VOBWQQTLFUYIUHMJBZCYXCGW listed in docker/nats/server.conf. */
    private const string SEED = 'SUAD3LTGPJHBFPJMOTGX6OIF6YDL2FZ2IGYQIOFORBGSLGYSF2PJWGWGFU';

    /** A valid user seed unknown to the server. */
    private const string UNKNOWN_SEED = 'SUAHNDAT4XZP3JOFBU5STXVC7473S7UBIOSJBUETHPUFHAMZIW7PY73WUM';

    #[TestWith([false])]
    #[TestWith([true])]
    public function testConnectsWithNkeyOnly(bool $verbose): void
    {
        $client = $this->clientWithNkey(self::SEED, $verbose);

        $deliveries = $client->subscribeIterator('events.*');
        $client->publish('events.happens', new Message('ok'));

        foreach ($deliveries as $delivery) {
            self::assertSame('events.happens', $delivery->subject);
            self::assertSame('ok', $delivery->message->payload);

            $deliveries->stop();
        }
    }

    public function testUnknownNkeyIsRejected(): void
    {
        $client = $this->clientWithNkey(self::UNKNOWN_SEED);

        self::expectException(AuthorizationViolation::class);

        $client->publish('events.happens', new Message('ok'));
    }

    public function testInvalidSeedIsRejectedBeforeConnect(): void
    {
        $client = $this->clientWithNkey('SUACSSL3UAHUDXKFSNVUZRF5UHPMWZ6BFDTJ7M6USDXIEDNPPQYYYCU3VA');

        self::expectException(InvalidNKey::class);

        $client->publish('events.happens', new Message('ok'));
    }

    /**
     * @param non-empty-string $seed
     */
    private function clientWithNkey(string $seed, bool $verbose = false): Client
    {
        return $this->clientWithConfig(new Config(
            urls: [$this->natsAddress()],
            verbose: $verbose,
            nkey: $seed,
            noResponders: true,
        ));
    }
}
