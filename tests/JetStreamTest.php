<?php

declare(strict_types=1);

namespace Thesis\Nats;

use Amp\Future;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use Thesis\Nats\Exception\ConsumerDoesNotExist;
use Thesis\Nats\Exception\ConsumerNotFound;
use Thesis\Nats\Exception\StreamDoesNotMatch;
use Thesis\Nats\Exception\StreamNotFound;
use Thesis\Nats\Exception\WrongLastMessageId;
use Thesis\Nats\Exception\WrongLastSequence;
use Thesis\Nats\Header\ExpectedLastMsgID;
use Thesis\Nats\Header\ExpectedLastSeq;
use Thesis\Nats\Header\ExpectedLastSubjSeq;
use Thesis\Nats\Header\ExpectedStream;
use Thesis\Nats\Header\MsgId;
use Thesis\Nats\Header\MsgTtl;
use Thesis\Nats\Header\ScheduleNext;
use Thesis\Nats\Header\Scheduler;
use Thesis\Nats\Header\Sequence;
use Thesis\Nats\Header\Stream;
use Thesis\Nats\Header\Subject;
use Thesis\Nats\Header\Timestamp;
use Thesis\Nats\JetStream\Api\AckPolicy;
use Thesis\Nats\JetStream\Api\ConsumerConfig;
use Thesis\Nats\JetStream\Api\ConsumerInfo;
use Thesis\Nats\JetStream\Api\DeliverPolicy;
use Thesis\Nats\JetStream\Api\StreamConfig;
use Thesis\Nats\JetStream\Delivery as JetStreamDelivery;
use Thesis\Time\TimeSpan;
use function Amp\async;
use function Amp\delay;
use function Amp\Future\awaitAll;
use function Amp\now;
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

        $stream->delete();
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

        $stream->delete();
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

        $stream->delete();
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

        $stream->delete();
    }

    public function testStreamInfo(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $subject = generateUniqueId(10);

        $stream = $js->createOrUpdateStream(new StreamConfig(generateUniqueId(10), subjects: [$subject]));

        $info = $stream->actualInfo();

        self::assertSame([$subject], $info->config->subjects);

        $stream->delete();
    }

    public function testDeleteStream(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $streamName = generateUniqueId(10);

        $stream = $js->createOrUpdateStream(new StreamConfig($streamName));

        self::assertTrue($stream->delete()->success);

        self::expectException(StreamNotFound::class);
        $stream->actualInfo();
    }

    public function testPurgeStream(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = $js->createOrUpdateStream(new StreamConfig(generateUniqueId(10)));

        $response = $stream->purge();

        self::assertTrue($response->success);
        self::assertSame(0, $response->purged);

        $stream->delete();
    }

    public function testStreamNames(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $subject = generateUniqueId(10);
        $stream = generateUniqueId(10);

        $js->createOrUpdateStream(new StreamConfig($stream, subjects: [$subject]));

        self::assertSame([$stream], [...$js->streamNames($subject)]);

        $js->deleteStream($stream);
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

        $js->deleteStream($stream);
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

        $stream->delete();
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

        $js->deleteStream($stream);
    }

    public function testUpdateUnknownConsumer(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = generateUniqueId(10);

        $js->createStream(new StreamConfig($stream));

        try {
            self::expectException(ConsumerDoesNotExist::class);
            $js->updateConsumer($stream, new ConsumerConfig(durableName: generateUniqueId(10)));
        } finally {
            $js->deleteStream($stream);
        }
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

        $stream->delete();
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

        $stream->delete();
    }

    public function testConsumerInfo(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = $js->createStream(new StreamConfig(generateUniqueId(10)));

        $consumerName = generateUniqueId(10);

        $consumer = $stream->createConsumer(new ConsumerConfig(durableName: $consumerName, ackPolicy: AckPolicy::Explicit));

        $consumerInfo = $consumer->actualInfo();

        self::assertEquals($consumer->info->config, $consumerInfo->config);

        $stream->delete();
    }

    public function testDeleteConsumer(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = $js->createStream(new StreamConfig(generateUniqueId(10)));

        $consumer = $stream->createConsumer(new ConsumerConfig(durableName: generateUniqueId(10), ackPolicy: AckPolicy::Explicit));

        self::assertTrue($consumer->delete()->success);

        try {
            self::expectException(ConsumerNotFound::class);
            $consumer->actualInfo();
        } finally {
            $stream->delete();
        }
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

        $stream->delete();
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

        $js->deleteStream($stream);
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

        $js->deleteStream($stream);
    }

    public function testPublishWrongLastStreamSequence(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $subject = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: generateUniqueId(10),
            subjects: ["{$subject}.*"],
        ));

        $stream->createConsumer(new ConsumerConfig(
            durableName: generateUniqueId(10),
            ackPolicy: AckPolicy::Explicit,
        ));

        try {
            self::expectException(WrongLastSequence::class);
            $js->publish("{$subject}.xxx", new Message(
                headers: (new Headers())
                    ->with(ExpectedLastSeq::Header, 1),
            ));
        } finally {
            $stream->delete();
        }
    }

    public function testPublishWrongLastSubjectSequence(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $subject = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: generateUniqueId(10),
            subjects: ["{$subject}.*"],
        ));

        $stream->createConsumer(new ConsumerConfig(
            durableName: generateUniqueId(10),
            ackPolicy: AckPolicy::Explicit,
        ));

        try {
            self::expectException(WrongLastSequence::class);
            $js->publish("{$subject}.xxx", new Message(
                headers: (new Headers())
                    ->with(ExpectedLastSubjSeq::Header, 1),
            ));
        } finally {
            $stream->delete();
        }
    }

    public function testPublishWrongLastMsgId(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $subject = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: generateUniqueId(10),
            subjects: ["{$subject}.*"],
        ));

        $stream->createConsumer(new ConsumerConfig(
            durableName: generateUniqueId(10),
            ackPolicy: AckPolicy::Explicit,
        ));

        try {
            self::expectException(WrongLastMessageId::class);
            $js->publish("{$subject}.xxx", new Message(
                headers: (new Headers())
                    ->with(ExpectedLastMsgID::header(), '123'),
            ));
        } finally {
            $stream->delete();
        }
    }

    public function testPublishStreamDoesNotMatch(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $subject = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: generateUniqueId(10),
            subjects: ["{$subject}.*"],
        ));

        $stream->createConsumer(new ConsumerConfig(
            durableName: generateUniqueId(10),
            ackPolicy: AckPolicy::Explicit,
        ));

        try {
            self::expectException(StreamDoesNotMatch::class);
            $js->publish("{$subject}.xxx", new Message(
                headers: (new Headers())
                    ->with(ExpectedStream::header(), 'xxx'),
            ));
        } finally {
            $stream->delete();
        }
    }

    public function testPublishMsgExpired(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $subject = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: generateUniqueId(10),
            subjects: ["{$subject}.*"],
            allowMessageTtl: true,
        ));

        $js->publish("{$subject}.xxx", new Message(
            headers: (new Headers())
                ->with(MsgTtl::Header, TimeSpan::fromSeconds(1)),
        ));

        self::assertSame(1, $stream->actualInfo()->state->messages);
        delay(1);
        self::assertSame(0, $stream->actualInfo()->state->messages);

        $stream->delete();
    }

    public function testPublishDuplicate(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $subject = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: generateUniqueId(10),
            subjects: ["{$subject}.*"],
            duplicateWindow: TimeSpan::fromSeconds(10),
        ));

        $response = $js->publish("{$subject}.xxx", new Message(
            headers: (new Headers())
                ->with(MsgId::header(), '123'),
        ));

        self::assertSame(1, $response->seq);
        self::assertNull($response->duplicate);

        for ($i = 0; $i < 5; ++$i) {
            $response = $js->publish("{$subject}.xxx", new Message(
                headers: (new Headers())
                    ->with(MsgId::header(), '123'),
            ));

            self::assertSame(1, $response->seq);
            self::assertTrue($response->duplicate);
        }

        $response = $js->publish("{$subject}.xxx", new Message(
            headers: (new Headers())
                ->with(MsgId::header(), '124'),
        ));

        self::assertSame(2, $response->seq);
        self::assertNull($response->duplicate);

        $stream->delete();
    }

    public function testPublishConsume(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $subject = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: generateUniqueId(10),
            subjects: ["{$subject}.*"],
            duplicateWindow: TimeSpan::fromSeconds(10),
        ));

        $publishedMessages = [];

        for ($i = 0; $i < 5; ++$i) {
            $payload = "Message#{$i}";
            $publishedMessages[] = $payload;

            $response = $js->publish("{$subject}.xxx", new Message(
                payload: $payload,
                headers: (new Headers())
                    ->with(MsgId::header(), "id:{$i}"),
            ));

            self::assertSame($i + 1, $response->seq);
        }

        $consumer = $stream->createConsumer(new ConsumerConfig(durableName: generateUniqueId(10), ackPolicy: AckPolicy::Explicit));

        self::assertSame(5, $consumer->actualInfo()->numPending);

        $counter = 0;
        $messages = [];

        $subscription = $consumer->consume(static function (JetStreamDelivery $delivery, Subscription $subscription) use (
            &$messages,
            &$counter,
        ): void {
            $messages[] = $delivery->message->payload;
            if (++$counter === 5) {
                $subscription->stop();
            }
        });

        $subscription->wait();
        self::assertSame($publishedMessages, $messages);
        self::assertSame(0, $consumer->actualInfo()->numPending);

        $stream->delete();
    }

    public function testPublishGet(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $subject = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: generateUniqueId(10),
            subjects: ["{$subject}.*"],
            duplicateWindow: TimeSpan::fromSeconds(10),
        ));

        $publishSubject = "{$subject}.xxx";

        for ($i = 0; $i < 2; ++$i) {
            $js->publish($publishSubject, new Message(
                payload: "Message#{$i}",
                headers: (new Headers())
                    ->with(MsgId::header(), "id:{$i}"),
            ));
        }

        $msg1 = $stream->getMessage(1);
        self::assertNotNull($msg1);
        self::assertNotNull($msg1->headers);
        self::assertSame($publishSubject, $msg1->headers->get(Subject::header()));
        self::assertSame(1, $msg1->headers->get(Sequence::header()));
        self::assertSame($stream->name, $msg1->headers->get(Stream::header()));
        self::assertSame('id:0', $msg1->headers->get(MsgId::header()));
        self::assertInstanceOf(\DateTimeImmutable::class, $msg1->headers->get(Timestamp::Header));
        self::assertSame('Message#0', $msg1->payload);

        $msg2 = $stream->getMessage(2);
        self::assertNotNull($msg2);
        self::assertNotNull($msg2->headers);
        self::assertSame($publishSubject, $msg2->headers->get(Subject::header()));
        self::assertSame(2, $msg2->headers->get(Sequence::header()));
        self::assertSame($stream->name, $msg2->headers->get(Stream::header()));
        self::assertSame('id:1', $msg2->headers->get(MsgId::header()));
        self::assertInstanceOf(\DateTimeImmutable::class, $msg2->headers->get(Timestamp::Header));
        self::assertSame('Message#1', $msg2->payload);
        self::assertEquals($msg2, $stream->getLastMessageForSubject($publishSubject));

        self::assertNull($stream->getMessage(3));
        self::assertNull($stream->getMessage(1, 'xxx'));

        $stream->delete();
    }

    public function testPublishDelete(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $subject = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: generateUniqueId(10),
            subjects: ["{$subject}.*"],
            duplicateWindow: TimeSpan::fromSeconds(10),
        ));

        $js->publish("{$subject}.xxx", new Message(
            payload: 'Message',
        ));

        $msg1 = $stream->getMessage(1);
        self::assertNotNull($msg1);

        $stream->deleteMessage(1);
        self::assertNull($stream->getMessage(1));

        $stream->delete();
    }

    public function testPublishSecureDelete(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $subject = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: generateUniqueId(10),
            subjects: ["{$subject}.*"],
            duplicateWindow: TimeSpan::fromSeconds(10),
        ));

        $js->publish("{$subject}.xxx", new Message(
            payload: 'Message',
        ));

        $msg1 = $stream->getMessage(1);
        self::assertNotNull($msg1);

        $stream->secureDeleteMessage(1);
        self::assertNull($stream->getMessage(1));

        $stream->delete();
    }

    public function testScheduleSingleMessage(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $stream = $js->createStream(new StreamConfig(
            name: generateUniqueId(10),
            subjects: [
                'recurrents',
                'scheduler.recurrents.*',
            ],
            allowMsgSchedules: true,
        ));

        $js->publish('scheduler.recurrents.1', new Message(
            payload: '{"id":1}',
            headers: (new Headers())
                ->with(Header\Schedule::Header, new \DateTimeImmutable('+2 seconds'))
                ->with(Header\ScheduleTarget::header(), 'recurrents'),
        ));

        $consumer = $stream->createOrUpdateConsumer(new ConsumerConfig(
            durableName: 'RecurrentsConsumer',
            deliverPolicy: DeliverPolicy::New,
            ackPolicy: AckPolicy::None,
            filterSubjects: ['recurrents'],
        ));

        $ts = now();
        $subscription = $consumer->consume(static function (JetStreamDelivery $delivery, Subscription $subscription) use ($ts): void {
            self::assertTrue(now() - $ts > 0.5);
            self::assertSame('{"id":1}', $delivery->message->payload);
            self::assertNotNull($delivery->message->headers);
            self::assertSame('scheduler.recurrents.1', $delivery->message->headers->get(Scheduler::header()));
            self::assertSame('purge', $delivery->message->headers->get(ScheduleNext::header()));
            $subscription->stop();
        });

        $subscription->wait();

        $stream->delete();
    }

    public function testConsumerUnsubscribeAll(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $subject = generateUniqueId(10);
        $streamName = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig(
            name: $streamName,
            subjects: ["{$subject}.*"],
        ));

        $consumer = $stream->createConsumer(new ConsumerConfig(
            durableName: generateUniqueId(10),
            ackPolicy: AckPolicy::Explicit,
        ));

        $completedCount = 0;
        $subscriptions = [];

        for ($i = 0; $i < 3; ++$i) {
            $subscriptions[] = $consumer
                ->consume(static fn() => null)
                ->onComplete(static function () use (&$completedCount): void {
                    ++$completedCount;
                });
        }

        delay(0.01);

        $consumer->unsubscribeAll();

        awaitAll(array_map(static fn(Subscription $subscription): Future => async($subscription->wait(...)), $subscriptions));

        self::assertSame(3, $completedCount, 'All iterators should complete after unsubscribeAll');

        $stream->delete();
    }

    #[DoesNotPerformAssertions]
    public function testConsumerUnsubscribeAllWithNoSubscriptions(): void
    {
        $client = $this->client();
        $js = $client->jetStream();

        $streamName = generateUniqueId(10);

        $stream = $js->createStream(new StreamConfig($streamName));

        $consumer = $stream->createConsumer(new ConsumerConfig(
            durableName: generateUniqueId(10),
            ackPolicy: AckPolicy::Explicit,
        ));

        $consumer->unsubscribeAll();

        $stream->delete();
    }
}
