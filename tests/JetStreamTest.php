<?php

declare(strict_types=1);

namespace Thesis\Nats;

use PHPUnit\Framework\Attributes\CoversClass;
use Thesis\Nats\Exception\ConsumerDoesNotExist;
use Thesis\Nats\Exception\ConsumerNotFound;
use Thesis\Nats\Exception\StreamNotFound;
use Thesis\Nats\JetStream\Api\AckPolicy;
use Thesis\Nats\JetStream\Api\ConsumerConfig;
use Thesis\Nats\JetStream\Api\ConsumerInfo;
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

        self::assertSame(-1, $info->limits->maxMemory);
        self::assertSame(-1, $info->limits->maxStorage);
    }

    public function testCreateStream(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $streamName = generateUniqueId(10);
        $subject = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig($streamName, subjects: [$subject]));

        self::assertSame($streamName, $stream->info->config->name);
        self::assertSame([$subject], $stream->info->config->subjects);

        $client->disconnect();
    }

    public function testUpdateStream(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $streamName = generateUniqueId(10);
        $subject1 = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig($streamName, subjects: [$subject1]));

        self::assertSame([$subject1], $stream->info->config->subjects);

        $subject2 = generateUniqueId(10);

        $info = $js->updateStream(new StreamConfig($streamName, subjects: [$subject1, $subject2]));
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

        $streamName = generateUniqueId(10);
        $subject = generateUniqueId(10);

        $stream = $js->createOrUpdateStream(new StreamConfig($streamName, subjects: [$subject]));

        self::assertSame([$subject], $stream->info->config->subjects);

        $client->disconnect();
    }

    public function testCreateOrUpdateExistStream(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $streamName = generateUniqueId(10);
        $subject1 = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig($streamName, subjects: [$subject1]));

        self::assertSame([$subject1], $stream->info->config->subjects);

        $subject2 = generateUniqueId(10);

        $stream = $js->createOrUpdateStream(new StreamConfig($streamName, subjects: [$subject1, $subject2]));
        self::assertSame([$subject1, $subject2], $stream->info->config->subjects);

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

        $streamName = generateUniqueId(10);

        $stream = $js->createOrUpdateStream(new StreamConfig($streamName));

        self::assertTrue($stream->delete()->success);

        self::expectException(StreamNotFound::class);
        $js->streamInfo($streamName);
    }

    public function testPurgeStream(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = $js->createOrUpdateStream(new StreamConfig(generateUniqueId(10)));

        $response = $stream->purge();

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

    public function testCreateConsumerOnUnknownStream(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        self::expectException(StreamNotFound::class);
        $js->createConsumer(generateUniqueId(10), new ConsumerConfig(durableName: generateUniqueId(10)));
    }

    public function testCreateConsumer(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = $js->createStream(new StreamConfig(generateUniqueId(10)));

        $consumerName = generateUniqueId(10);

        $consumer = $stream->createConsumer(new ConsumerConfig(durableName: $consumerName, ackPolicy: AckPolicy::Explicit));

        self::assertSame($consumerName, $consumer->info->name);
        self::assertSame(AckPolicy::Explicit, $consumer->info->config->ackPolicy);
        self::assertSame(0, $consumer->info->numPending);

        $client->disconnect();
    }

    public function testUpdateConsumer(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = generateUniqueId(10);

        $js->createStream(new StreamConfig($stream));

        $consumerName = generateUniqueId(10);

        $consumer = $js->createConsumer($stream, new ConsumerConfig(durableName: $consumerName, ackPolicy: AckPolicy::Explicit));

        $updatedInfo = $js->updateConsumer($stream, new ConsumerConfig(durableName: $consumerName, description: 'Test Consumer', ackPolicy: AckPolicy::Explicit));

        self::assertSame($consumer->info->config->durableName, $updatedInfo->config->durableName);
        self::assertSame($consumer->info->config->ackPolicy, $updatedInfo->config->ackPolicy);
        self::assertNull($consumer->info->config->description);
        self::assertSame('Test Consumer', $updatedInfo->config->description);

        $client->disconnect();
    }

    public function testUpdateUnknownConsumer(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = generateUniqueId(10);

        $js->createStream(new StreamConfig($stream));

        self::expectException(ConsumerDoesNotExist::class);
        $js->updateConsumer($stream, new ConsumerConfig(durableName: generateUniqueId(10)));
    }

    public function testCreateOrUpdateNewConsumer(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = $js->createStream(new StreamConfig(generateUniqueId(10)));

        $consumerName = generateUniqueId(10);

        $consumer = $stream->createOrUpdateConsumer(new ConsumerConfig(durableName: $consumerName, ackPolicy: AckPolicy::Explicit));

        self::assertSame($consumerName, $consumer->info->name);
        self::assertSame(AckPolicy::Explicit, $consumer->info->config->ackPolicy);
        self::assertSame(0, $consumer->info->numPending);

        $client->disconnect();
    }

    public function testCreateOrUpdateExistConsumer(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = $js->createStream(new StreamConfig(generateUniqueId(10)));

        $consumerName = generateUniqueId(10);

        $createdConsumer = $stream->createConsumer(new ConsumerConfig(durableName: $consumerName, ackPolicy: AckPolicy::Explicit));

        $updatedConsumer = $stream->createOrUpdateConsumer(new ConsumerConfig(durableName: $consumerName, description: 'Test Consumer', ackPolicy: AckPolicy::Explicit));

        self::assertSame($createdConsumer->info->config->durableName, $updatedConsumer->info->config->durableName);
        self::assertSame($createdConsumer->info->config->ackPolicy, $updatedConsumer->info->config->ackPolicy);
        self::assertNull($createdConsumer->info->config->description);
        self::assertSame('Test Consumer', $updatedConsumer->info->config->description);

        $client->disconnect();
    }

    public function testConsumerInfo(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = $js->createStream(new StreamConfig(generateUniqueId(10)));

        $consumerName = generateUniqueId(10);

        $consumer = $stream->createConsumer(new ConsumerConfig(durableName: $consumerName, ackPolicy: AckPolicy::Explicit));

        $consumerInfo = $js->consumerInfo($stream->name, $consumerName);

        self::assertEquals($consumer->info->config, $consumerInfo->config);

        $client->disconnect();
    }

    public function testDeleteConsumer(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = $js->createStream(new StreamConfig(generateUniqueId(10)));

        $consumer = $stream->createConsumer(new ConsumerConfig(durableName: generateUniqueId(10), ackPolicy: AckPolicy::Explicit));

        self::assertTrue($consumer->delete()->success);

        self::expectException(ConsumerNotFound::class);
        $js->consumerInfo($stream->name, $consumer->name);
    }

    public function testPauseResumeConsumer(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = $js->createStream(new StreamConfig(generateUniqueId(10)));

        $consumer = $stream->createConsumer(new ConsumerConfig(durableName: generateUniqueId(10), ackPolicy: AckPolicy::Explicit));

        $until = (new \DateTimeImmutable())->add(new \DateInterval('P1D'));

        $response = $consumer->pause($until);

        self::assertTrue($response->paused);
        self::assertEquals($until->format('Y-m-d H:i:s'), $response->pauseUntil->format('Y-m-d H:i:s'));
        self::assertEquals(24, $response->pauseRemaining?->toHours());

        $response = $consumer->resume();

        self::assertFalse($response->paused);
        self::assertNull($response->pauseRemaining);
        self::assertEquals('0001-01-01 00:00:00', $response->pauseUntil->format('Y-m-d H:i:s'));

        $client->disconnect();
    }

    public function testConsumerNames(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = generateUniqueId(10);

        $js->createStream(new StreamConfig($stream));

        $consumer1 = generateUniqueId(10);
        $consumer2 = generateUniqueId(10);

        $consumers = [$consumer1, $consumer2];
        sort($consumers);

        $js->createConsumer($stream, new ConsumerConfig(
            durableName: $consumer1,
            ackPolicy: AckPolicy::Explicit,
        ));

        $js->createConsumer($stream, new ConsumerConfig(
            durableName: $consumer2,
            ackPolicy: AckPolicy::Explicit,
        ));

        $consumerNames = [...$js->consumerNames($stream)];
        sort($consumerNames);

        self::assertCount(2, $consumerNames);
        self::assertEquals($consumers, $consumerNames);

        $client->disconnect();
    }

    public function testConsumerList(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = generateUniqueId(10);

        $js->createStream(new StreamConfig($stream));

        $consumer1 = generateUniqueId(10);
        $consumer2 = generateUniqueId(10);

        $consumers = [$consumer1, $consumer2];
        sort($consumers);

        $js->createConsumer($stream, new ConsumerConfig(
            durableName: $consumer1,
            ackPolicy: AckPolicy::Explicit,
        ));

        $js->createConsumer($stream, new ConsumerConfig(
            durableName: $consumer2,
            ackPolicy: AckPolicy::Explicit,
        ));

        $list = [...$js->consumerList($stream)];

        self::assertCount(2, $list);

        $consumerNames = array_map(
            static fn(ConsumerInfo $info): ?string => $info->config->durableName,
            $list,
        );

        sort($consumerNames);

        self::assertEquals($consumers, $consumerNames);

        $client->disconnect();
    }
}
