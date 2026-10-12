<?php

declare(strict_types=1);

namespace Thesis\Nats\Internal\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Thesis\Nats\Exception\InvalidBucketName;
use Thesis\Nats\Exception\InvalidKey;
use Thesis\Nats\Exception\InvalidName;
use Thesis\Nats\Exception\InvalidSubject;
use Thesis\Nats\Exception\ObjectIsInvalid;

#[CoversClass(Validate::class)]
final class ValidateTest extends TestCase
{
    #[TestWith(['foo'])]
    #[TestWith(['foo.bar'])]
    #[TestWith(['$JS.API.INFO'])]
    #[TestWith(['_INBOX.abc.1'])]
    #[TestWith(['foo*.bar>'])] // not whole tokens, so literal characters, as in nats-server
    public function testValidPublishSubject(string $subject): void
    {
        $this->expectNotToPerformAssertions();
        Validate::publishSubject($subject);
    }

    #[TestWith(['', 'it is empty'])]
    #[TestWith(['foo bar', 'it contains whitespace'])]
    #[TestWith(["foo\tbar", 'it contains whitespace'])]
    #[TestWith(["foo\r\nbar", 'it contains whitespace'])]
    #[TestWith(["foo\x00bar", 'it contains whitespace'])]
    #[TestWith(['.foo', 'it contains an empty token'])]
    #[TestWith(['foo.', 'it contains an empty token'])]
    #[TestWith(['foo..bar', 'it contains an empty token'])]
    #[TestWith(['foo.*', "the wildcard '*' is not allowed"])]
    #[TestWith(['foo.>', "the wildcard '>' is not allowed"])]
    #[TestWith(['*', "the wildcard '*' is not allowed"])]
    public function testInvalidPublishSubject(string $subject, string $reason): void
    {
        $this->expectExceptionObject(InvalidSubject::because($subject, $reason));
        Validate::publishSubject($subject);
    }

    #[TestWith(['foo'])]
    #[TestWith(['foo.*'])]
    #[TestWith(['*.bar.*'])]
    #[TestWith(['foo.>'])]
    #[TestWith(['>'])]
    #[TestWith(['foo.*.>'])]
    public function testValidSubscribeSubject(string $subject): void
    {
        $this->expectNotToPerformAssertions();
        Validate::subscribeSubject($subject);
    }

    #[TestWith(['', 'it is empty'])]
    #[TestWith(['foo bar', 'it contains whitespace'])]
    #[TestWith(['foo..*', 'it contains an empty token'])]
    #[TestWith(['foo..>', 'it contains an empty token'])]
    #[TestWith(['>.foo', "the wildcard '>' must be the last token"])]
    #[TestWith(['foo.>.*', "the wildcard '>' must be the last token"])]
    public function testInvalidSubscribeSubject(string $subject, string $reason): void
    {
        $this->expectExceptionObject(InvalidSubject::because($subject, $reason));
        Validate::subscribeSubject($subject);
    }

    #[TestWith(['srv.api', null])]
    #[TestWith(['srv.*', null])]
    #[TestWith(['srv.>', "the wildcard '>' is not allowed"])]
    #[TestWith(['srv..api', 'it contains an empty token'])]
    public function testSubjectPrefix(string $subject, ?string $reason): void
    {
        if ($reason !== null) {
            $this->expectExceptionObject(InvalidSubject::because($subject, $reason));
        } else {
            $this->expectNotToPerformAssertions();
        }

        Validate::subjectPrefix($subject);
    }

    #[TestWith(['workers', null])]
    #[TestWith(['workers.v1', null])]
    #[TestWith(['', 'it is empty'])]
    #[TestWith(['work ers', 'it contains whitespace'])]
    #[TestWith(["workers\n", 'it contains whitespace'])]
    public function testQueueGroup(string $queueGroup, ?string $reason): void
    {
        if ($reason !== null) {
            $this->expectExceptionObject(InvalidSubject::forQueueGroup($queueGroup, $reason));
        } else {
            $this->expectNotToPerformAssertions();
        }

        Validate::queueGroup($queueGroup);
    }

    #[TestWith(['ORDERS'])]
    #[TestWith(['orders-v1_2'])]
    #[TestWith(['KV_bucket'])]
    #[TestWith(['имя'])]
    public function testValidName(string $name): void
    {
        $this->expectNotToPerformAssertions();
        Validate::name($name);
    }

    #[TestWith(['orders.v1'])]
    #[TestWith(['orders*'])]
    #[TestWith(['orders>'])]
    #[TestWith(['orders/v1'])]
    #[TestWith(['orders\v1'])]
    #[TestWith(['my orders'])]
    #[TestWith(["orders\t"])]
    #[TestWith(["orders\x7f"])]
    public function testNameWithForbiddenCharacters(string $name): void
    {
        $this->expectExceptionObject(InvalidName::because(
            $name,
            "it must not contain '.', '*', '>', '/', '\\', whitespace or control characters",
        ));
        Validate::name($name);
    }

    public function testEmptyName(): void
    {
        $this->expectExceptionObject(InvalidName::because('', 'it is empty'));
        Validate::name('');
    }

    public function testNameLength(): void
    {
        Validate::name(str_repeat('a', 255));

        $name = str_repeat('a', 256);
        $this->expectExceptionObject(InvalidName::because($name, 'it is longer than 255 bytes'));
        Validate::name($name);
    }

    #[TestWith(['Echo', true])]
    #[TestWith(['echo-v1_2', true])]
    #[TestWith(['', false])]
    #[TestWith(['api.echo', false])]
    #[TestWith(['echo*', false])]
    #[TestWith(['echo service', false])]
    public function testEndpointName(string $name, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(InvalidName::class);
        } else {
            $this->expectNotToPerformAssertions();
        }

        Validate::endpointName($name);
    }

    #[TestWith(['jobs', true])]
    #[TestWith(['A-z_0/9=', true])]
    #[TestWith(['sixteen-chars-ok', true])]
    #[TestWith(['', false])]
    #[TestWith(['seventeen-chars-x', false])]
    #[TestWith(['jobs.high', false])]
    #[TestWith(['jobs*', false])]
    #[TestWith(['my jobs', false])]
    public function testPriorityGroup(string $group, bool $valid): void
    {
        if (!$valid) {
            $this->expectExceptionObject(InvalidName::because(
                $group,
                "only 'A-Z', 'a-z', '0-9', '-', '_', '/' and '=' are allowed, at most 16 characters",
            ));
        } else {
            $this->expectNotToPerformAssertions();
        }

        Validate::priorityGroup($group);
    }

    #[TestWith(['bucket', true])]
    #[TestWith(['my-Bucket_1', true])]
    #[TestWith(['', false])]
    #[TestWith(['my.bucket', false])]
    #[TestWith(['my bucket', false])]
    #[TestWith(['bucket*', false])]
    #[TestWith(['bucket/1', false])]
    public function testBucket(string $bucket, bool $valid): void
    {
        if (!$valid) {
            $this->expectExceptionObject(InvalidBucketName::because($bucket, "only 'A-Z', 'a-z', '0-9', '-' and '_' are allowed"));
        } else {
            $this->expectNotToPerformAssertions();
        }

        Validate::bucket($bucket);
    }

    #[TestWith(['key'])]
    #[TestWith(['a.b.c'])]
    #[TestWith(['path/to/key=1_2-3'])]
    public function testValidKey(string $key): void
    {
        $this->expectNotToPerformAssertions();
        Validate::key($key);
    }

    #[TestWith(['', "only 'A-Z', 'a-z', '0-9', '-', '/', '_', '=' and '.' are allowed"])]
    #[TestWith(['my key', "only 'A-Z', 'a-z', '0-9', '-', '/', '_', '=' and '.' are allowed"])]
    #[TestWith(['key*', "only 'A-Z', 'a-z', '0-9', '-', '/', '_', '=' and '.' are allowed"])]
    #[TestWith(['a.>', "only 'A-Z', 'a-z', '0-9', '-', '/', '_', '=' and '.' are allowed"])]
    #[TestWith(['.key', 'it contains an empty token'])]
    #[TestWith(['key.', 'it contains an empty token'])]
    #[TestWith(['a..b', 'it contains an empty token'])]
    public function testInvalidKey(string $key, string $reason): void
    {
        $this->expectExceptionObject(InvalidKey::because($key, $reason));
        Validate::key($key);
    }

    #[TestWith(['key', null])]
    #[TestWith(['a.*', null])]
    #[TestWith(['a.*.c', null])]
    #[TestWith(['>', null])]
    #[TestWith(['a.>', null])]
    #[TestWith(['a.>.b', "the wildcard '>' must be the last token"])]
    #[TestWith(['a..b', 'it contains an empty token'])]
    #[TestWith(['a b', "only 'A-Z', 'a-z', '0-9', '-', '/', '_', '=', '.', '*' and '>' are allowed"])]
    public function testKeyFilter(string $key, ?string $reason): void
    {
        if ($reason !== null) {
            $this->expectExceptionObject(InvalidKey::because($key, $reason));
        } else {
            $this->expectNotToPerformAssertions();
        }

        Validate::keyFilter($key);
    }

    public function testObjectName(): void
    {
        Validate::objectName('any name, even with spaces / and dots.');

        $this->expectExceptionObject(new ObjectIsInvalid('Object name is empty.'));
        Validate::objectName('');
    }
}
