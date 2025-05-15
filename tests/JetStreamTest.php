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

    public function testCreateConsumerOnUnknownStream(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = generateUniqueId(10);

        self::expectException(StreamNotFound::class);
        $js->createConsumer($stream, new ConsumerConfig(durableName: generateUniqueId(10)));
    }

    public function testCreateConsumer(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = generateUniqueId(10);

        $js->createStream(new StreamConfig($stream));

        $consumer = generateUniqueId(10);

        $info = $js->createConsumer($stream, new ConsumerConfig(durableName: $consumer, ackPolicy: AckPolicy::Explicit));

        self::assertSame($consumer, $info->name);
        self::assertSame(AckPolicy::Explicit, $info->config->ackPolicy);
        self::assertSame(0, $info->numPending);

        $client->disconnect();
    }

    public function testUpdateConsumer(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = generateUniqueId(10);

        $js->createStream(new StreamConfig($stream));

        $consumer = generateUniqueId(10);

        $createdInfo = $js->createConsumer($stream, new ConsumerConfig(durableName: $consumer, ackPolicy: AckPolicy::Explicit));

        $updatedInfo = $js->updateConsumer($stream, new ConsumerConfig(durableName: $consumer, description: 'Test Consumer', ackPolicy: AckPolicy::Explicit));

        self::assertSame($createdInfo->config->durableName, $updatedInfo->config->durableName);
        self::assertSame($createdInfo->config->ackPolicy, $updatedInfo->config->ackPolicy);
        self::assertNull($createdInfo->config->description);
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

        $stream = generateUniqueId(10);

        $js->createStream(new StreamConfig($stream));

        $consumer = generateUniqueId(10);

        $info = $js->createOrUpdateConsumer($stream, new ConsumerConfig(durableName: $consumer, ackPolicy: AckPolicy::Explicit));

        self::assertSame($consumer, $info->name);
        self::assertSame(AckPolicy::Explicit, $info->config->ackPolicy);
        self::assertSame(0, $info->numPending);

        $client->disconnect();
    }

    public function testCreateOrUpdateExistConsumer(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = generateUniqueId(10);

        $js->createStream(new StreamConfig($stream));

        $consumer = generateUniqueId(10);

        $createdInfo = $js->createConsumer($stream, new ConsumerConfig(durableName: $consumer, ackPolicy: AckPolicy::Explicit));

        $updatedInfo = $js->createOrUpdateConsumer($stream, new ConsumerConfig(durableName: $consumer, description: 'Test Consumer', ackPolicy: AckPolicy::Explicit));

        self::assertSame($createdInfo->config->durableName, $updatedInfo->config->durableName);
        self::assertSame($createdInfo->config->ackPolicy, $updatedInfo->config->ackPolicy);
        self::assertNull($createdInfo->config->description);
        self::assertSame('Test Consumer', $updatedInfo->config->description);

        $client->disconnect();
    }

    public function testConsumerInfo(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = generateUniqueId(10);

        $js->createStream(new StreamConfig($stream));

        $consumer = generateUniqueId(10);

        $createdInfo = $js->createConsumer($stream, new ConsumerConfig(durableName: $consumer, ackPolicy: AckPolicy::Explicit));

        $consumerInfo = $js->consumerInfo($stream, $consumer);

        self::assertEquals($createdInfo->config, $consumerInfo->config);

        $client->disconnect();
    }

    public function testDeleteConsumer(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = generateUniqueId(10);

        $js->createStream(new StreamConfig($stream));

        $consumer = generateUniqueId(10);

        $js->createConsumer($stream, new ConsumerConfig(durableName: $consumer, ackPolicy: AckPolicy::Explicit));

        self::assertTrue($js->deleteConsumer($stream, $consumer)->success);

        self::expectException(ConsumerNotFound::class);
        $js->consumerInfo($stream, $consumer);
    }

    public function testPauseResumeConsumer(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = generateUniqueId(10);

        $js->createStream(new StreamConfig($stream));

        $consumer = generateUniqueId(10);

        $js->createConsumer($stream, new ConsumerConfig(durableName: $consumer, ackPolicy: AckPolicy::Explicit));

        $until = (new \DateTimeImmutable())->add(new \DateInterval('P1D'));

        $response = $js->pauseConsumer($stream, $consumer, $until);

        self::assertTrue($response->paused);
        self::assertEquals($until->format('Y-m-d H:i:s'), $response->pauseUntil->format('Y-m-d H:i:s'));
        self::assertEquals(24, $response->pauseRemaining?->toHours());

        $response = $js->resumeConsumer($stream, $consumer);

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
