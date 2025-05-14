<?php

declare(strict_types=1);

namespace Thesis\Nats;

use PHPUnit\Framework\Attributes\CoversClass;
use Thesis\Nats\Exception\StreamNotFound;
use Thesis\Nats\JetStream\Api\StreamConfig;
use function Thesis\Nats\Internal\Id\generateUniqueId;

#[CoversClass(JetStream::class)]
final class JetStreamTest extends NatsTestCase
{
    public function testAccountInfo(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $info = $js->accountInfo();

        self::assertSame(0, $info->memory);
        self::assertSame(0, $info->storage);
    }

    public function testCreateStream(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = generateUniqueId(10);
        $subject = generateUniqueId(10);

        $info = $js->createStream(new StreamConfig($stream, subjects: [$subject]));

        self::assertSame($stream, $info->config->name);
        self::assertSame([$subject], $info->config->subjects);

        $client->disconnect();
    }

    public function testUpdateStream(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = generateUniqueId(10);
        $subject1 = generateUniqueId(10);

        $info = $js->createStream(new StreamConfig($stream, subjects: [$subject1]));

        self::assertSame([$subject1], $info->config->subjects);

        $subject2 = generateUniqueId(10);

        $info = $js->updateStream(new StreamConfig($stream, subjects: [$subject1, $subject2]));
        self::assertSame([$subject1, $subject2], $info->config->subjects);

        $client->disconnect();
    }

    public function testUpdateUnknownStream(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        self::expectException(StreamNotFound::class);
        $js->updateStream(new StreamConfig(generateUniqueId(10)));
    }

    public function testCreateOrUpdateNewStream(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = generateUniqueId(10);
        $subject = generateUniqueId(10);

        $info = $js->createOrUpdateStream(new StreamConfig($stream, subjects: [$subject]));

        self::assertSame([$subject], $info->config->subjects);

        $client->disconnect();
    }

    public function testCreateOrUpdateExistStream(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = generateUniqueId(10);
        $subject1 = generateUniqueId(10);

        $info = $js->createStream(new StreamConfig($stream, subjects: [$subject1]));

        self::assertSame([$subject1], $info->config->subjects);

        $subject2 = generateUniqueId(10);

        $info = $js->createOrUpdateStream(new StreamConfig($stream, subjects: [$subject1, $subject2]));
        self::assertSame([$subject1, $subject2], $info->config->subjects);

        $client->disconnect();
    }

    public function testStreamInfo(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = generateUniqueId(10);
        $subject = generateUniqueId(10);

        $js->createOrUpdateStream(new StreamConfig($stream, subjects: [$subject]));

        $info = $js->streamInfo($stream);

        self::assertSame([$subject], $info->config->subjects);

        $client->disconnect();
    }

    public function testDeleteStream(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = generateUniqueId(10);

        $js->createOrUpdateStream(new StreamConfig($stream));

        self::assertTrue($js->deleteStream($stream)->success);

        self::expectException(StreamNotFound::class);
        $js->streamInfo($stream);
    }

    public function testPurgeStream(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = generateUniqueId(10);

        $js->createOrUpdateStream(new StreamConfig($stream));

        $response = $js->purgeStream($stream);

        self::assertTrue($response->success);
        self::assertSame(0, $response->purged);

        $client->disconnect();
    }

    public function testStreamNames(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $subject = generateUniqueId(10);
        $stream = generateUniqueId(10);

        $js->createOrUpdateStream(new StreamConfig($stream, subjects: [$subject]));

        self::assertSame([$stream], [...$js->streamNames($subject)]);

        $client->disconnect();
    }

    public function testStreamList(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $subject = generateUniqueId(10);
        $stream = generateUniqueId(10);

        $js->createOrUpdateStream(new StreamConfig($stream, subjects: [$subject]));

        $list = [...$js->streamList($subject)];
        self::assertCount(1, $list);

        self::assertSame([$subject], $list[0]->config->subjects);

        $client->disconnect();
    }
}
