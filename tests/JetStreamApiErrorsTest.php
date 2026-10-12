<?php

declare(strict_types=1);

namespace Thesis\Nats;

use PHPUnit\Framework\Attributes\CoversClass;
use Thesis\Nats\Exception\ConsumerAlreadyExists;
use Thesis\Nats\Exception\JetStreamApiException;
use Thesis\Nats\Exception\JetStreamNotEnabledForAccount;
use Thesis\Nats\Exception\MessageSizeExceedsMaximum;
use Thesis\Nats\Exception\StreamNameAlreadyInUse;
use Thesis\Nats\Exception\StreamNotFound;
use Thesis\Nats\Exception\StreamSealed;
use Thesis\Nats\Exception\StreamStoreFailed;
use Thesis\Nats\Exception\UnknownJetStreamApiError;
use Thesis\Nats\JetStream\Api\AckPolicy;
use Thesis\Nats\JetStream\Api\ConsumerConfig;
use Thesis\Nats\JetStream\Api\DiscardPolicy;
use Thesis\Nats\JetStream\Api\RetentionPolicy;
use Thesis\Nats\JetStream\Api\StreamConfig;
use function Thesis\Nats\Internal\Id\generateUniqueId;

#[CoversClass(JetStreamApiException::class)]
final class JetStreamApiErrorsTest extends NatsTestCase
{
    public function testTypedErrorCarriesCodes(): void
    {
        $js = $this->client()->jetStream();

        try {
            $js->streamInfo(generateUniqueId(10));
            self::fail('Expected the stream to be not found.');
        } catch (StreamNotFound $e) {
            self::assertSame(404, $e->getCode());
            self::assertSame(10059, $e->errCode);
            self::assertSame('stream not found', $e->description);
            self::assertSame('stream not found', $e->getMessage());
        }
    }

    public function testUnknownErrorIsUnknownJetStreamApiError(): void
    {
        $js = $this->client()->jetStream();

        $stream = $js->createStream(new StreamConfig(
            name: generateUniqueId(10),
            subjects: [generateUniqueId(10)],
            retention: RetentionPolicy::WorkQueue,
        ));

        try {
            // 10084 has no dedicated exception.
            $stream->createConsumer(new ConsumerConfig(durableName: generateUniqueId(10), ackPolicy: AckPolicy::None));
            self::fail('Expected the consumer to be rejected.');
        } catch (JetStreamApiException $e) {
            self::assertSame(UnknownJetStreamApiError::class, $e::class);
            self::assertSame(400, $e->getCode());
            self::assertSame(10084, $e->errCode);
            self::assertStringContainsString('requires explicit ack policy', $e->description);
        } finally {
            $stream->delete();
        }
    }

    public function testStreamNameAlreadyInUse(): void
    {
        $js = $this->client()->jetStream();

        $name = generateUniqueId(10);
        $stream = $js->createStream(new StreamConfig($name, subjects: [generateUniqueId(10)]));

        try {
            self::expectException(StreamNameAlreadyInUse::class);
            $js->createStream(new StreamConfig($name, subjects: [generateUniqueId(10)]));
        } finally {
            $stream->delete();
        }
    }

    public function testConsumerAlreadyExists(): void
    {
        $js = $this->client()->jetStream();

        $stream = $js->createStream(new StreamConfig(generateUniqueId(10), subjects: [generateUniqueId(10)]));
        $consumer = generateUniqueId(10);
        $stream->createConsumer(new ConsumerConfig(durableName: $consumer));

        try {
            self::expectException(ConsumerAlreadyExists::class);
            $stream->createConsumer(new ConsumerConfig(durableName: $consumer, description: 'changed'));
        } finally {
            $stream->delete();
        }
    }

    public function testStreamSealed(): void
    {
        $js = $this->client()->jetStream();

        $subject = generateUniqueId(10);
        $config = new StreamConfig(generateUniqueId(10), subjects: [$subject]);
        $stream = $js->createStream($config);
        $js->publish($subject, new Message('x'));
        $js->updateStream($config->seal());

        try {
            self::expectException(StreamSealed::class);
            $stream->deleteMessage(1);
        } finally {
            $stream->delete();
        }
    }

    public function testMessageSizeExceedsMaximum(): void
    {
        $js = $this->client()->jetStream();

        $subject = generateUniqueId(10);
        $stream = $js->createStream(new StreamConfig(generateUniqueId(10), subjects: [$subject], maxMessageSize: 10));

        try {
            self::expectException(MessageSizeExceedsMaximum::class);
            $js->publish($subject, new Message(str_repeat('x', 100)));
        } finally {
            $stream->delete();
        }
    }

    public function testStreamStoreFailed(): void
    {
        $js = $this->client()->jetStream();

        $subject = generateUniqueId(10);
        $stream = $js->createStream(new StreamConfig(
            name: generateUniqueId(10),
            subjects: [$subject],
            discard: DiscardPolicy::New,
            maxMessages: 1,
        ));
        $js->publish($subject, new Message('x'));

        try {
            $js->publish($subject, new Message('x'));
            self::fail('Expected the stream to reject the message.');
        } catch (StreamStoreFailed $e) {
            self::assertSame(10077, $e->errCode);
            self::assertSame('maximum messages exceeded', $e->description);
        } finally {
            $stream->delete();
        }
    }

    public function testJetStreamNotEnabledForAccount(): void
    {
        // The system account has no JetStream.
        $js = $this->clientWithConfig(new Config(
            urls: $this->config()->urls,
            user: 'admin',
            password: 'Pswd1',
            noResponders: true,
        ))->jetStream();

        self::expectException(JetStreamNotEnabledForAccount::class);
        $js->accountInfo();
    }
}
