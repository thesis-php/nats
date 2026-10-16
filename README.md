# Thesis Nats

Pure non-blocking (fiber based) strictly typed full-featured PHP driver for NATS.

## Features
- [NATS Core](https://docs.nats.io/nats-concepts/core-nats) 
  - [Publish-Subscribe](#pub-sub)
  - [Queues](#queues)
  - [Request-Reply](#request-reply)
  - [Request Many](#request-many)
  - [Auto-unsubscribe](#auto-unsubscribe)
  - [Slow consumers](#slow-consumers)
- [NATS JetStream](https://docs.nats.io/nats-concepts/jetstream)
  - [Publish](#publish)
  - [Publish Options](#publish-options)
  - [Fetch Batch](#fetch-batch)
  - [Fetch Bytes](#fetch-bytes)
  - [Fetch Immediate](#fetch-immediate)
  - [Push Consumer](#push-consumer)
  - [Pull Consumer](#pull-consumer)
  - [Get message](#get-message)
- [NATS KV](https://docs.nats.io/nats-concepts/jetstream/key-value-store)
  - [Store key values](#store-key-values)
  - [Create and update buckets](#create-and-update-buckets)
  - [Create, update and expire keys](#create-update-and-expire-keys)
  - [Keys and history](#keys-and-history)
  - [Bucket status](#bucket-status)
  - [Purge deletes](#purge-deletes)
  - [Watch KV](#watch-kv)
  - [Mirrors and sources](#mirrors-and-sources)
- [NATS ObjectStore](https://docs.nats.io/nats-concepts/jetstream/obj_store)
  - [Store objects](#store-objects-in-the-buckets)
  - [Watch Object Store](#watch-object-store)
- [NATS CRDT](#nats-crdt)
  - [Add Counter](#add-counter)
  - [Get Counter](#get-counter)
  - [Get Counters](#get-counters)
- [NATS Message Scheduler](#nats-message-scheduler)
  - [Single scheduled message](#single-scheduled-message)
- [NATS JetStream Batch Publishing](#nats-jetstream-batch-publishing)
  - [Publish using `PublishBatch`](#publish-using-publishbatch)
  - [Publish using `JetStream`](#publish-batch-using-jetstream)
- [Nats Service Api](#nats-service-api)
  - [Micro Service](#micro-service) 
  - [Endpoints](#service-endpoints)
  - [Groups](#groups)
  - [Errors](#service-errors)
  - [Custom stats](#custom-stats)
  - [Stopping a service](#stopping-a-service)

## Installation

```shell
composer require thesis/nats
```

## Nats Core

The library implements the full functionality of NATS Core, including pub-sub, queues and request–reply.

#### Pub-Sub

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use function Amp\delay;
use function Amp\trapSignal;

$nc = new Nats\Client(Nats\Config::default());

$nc->subscribe('foo.*', static function (Nats\Delivery $delivery): void {
    dump("Received message {$delivery->message->payload} for consumer#1");
});

$nc->subscribe('foo.>', static function (Nats\Delivery $delivery): void {
    dump("Received message {$delivery->message->payload} for consumer#2");
});

$subscription = $nc->subscribe('foo.bar', static function (Nats\Delivery $delivery): void {
    dump("Received message {$delivery->message->payload} for consumer#3");
});

$nc->publish('foo.bar', new Nats\Message('Hello World!')); // visible for all consumers
$nc->publish('foo.baz', new Nats\Message('Hello World!')); // visible only for 1-2 consumers
$nc->publish('foo.bar.baz', new Nats\Message('Hello World!')); // visible only for 2 consumer

$subscription->stop();
$nc->publish('foo.bar', new Nats\Message('Hello World!')); // visible for 1-2 consumers

trapSignal([\SIGTERM, \SIGINT]);

$nc->drain();
```

The connection lives as long as the client: keep a reference to the client (or to an object that uses it, such as a subscription, a JetStream context or a consumer) while you need it. A client that is no longer referenced closes its connection without draining, so prefer an explicit `drain()` or `stop()`.

#### Queues

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use function Amp\trapSignal;

$nc = new Nats\Client(Nats\Config::default());

$nc->subscribe(
    subject: 'foo.>',
    handler: static function (Nats\Delivery $delivery): void {
        dump("Received message {$delivery->message->payload} for consumer#1");
    },
    queueGroup: 'test',
);

$nc->subscribe(
    subject: 'foo.>',
    handler: static function (Nats\Delivery $delivery): void {
        dump("Received message {$delivery->message->payload} for consumer#2");
    },
    queueGroup: 'test',
);

$nc->subscribe(
    subject: 'foo.>',
    handler: static function (Nats\Delivery $delivery): void {
        dump("Received message {$delivery->message->payload} for consumer#3");
    },
    queueGroup: 'test',
);

$nc->publish('foo.bar', new Nats\Message('x'));
$nc->publish('foo.baz', new Nats\Message('y'));
$nc->publish('foo.bar.baz', new Nats\Message('z'));

trapSignal([\SIGTERM, \SIGINT]);

$nc->drain();
```

#### Request-reply

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;

$nc = new Nats\Client(Nats\Config::default());

$nc->subscribe('foo.>', static function (Nats\Delivery $delivery): void {
    dump("Received request {$delivery->message->payload}");
    $delivery->reply(new Nats\Message(strrev($delivery->message->payload ?? '')));
});

$response = $nc->request('foo.bar', new Nats\Message('Hello World!'));
dump("Received response {$response->message->payload}");

$nc->drain();
```

A request waits for the reply until it is cancelled, unless a timeout is given: per call, or for all requests with `Nats\Config::$requestTimeout`
(`?request_timeout=5` in the URI), which also applies to the JetStream API calls. A request without a reply in time throws `Nats\Exception\RequestTimedOut`:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;

$nc = new Nats\Client(Nats\Config::fromURI('tcp://127.0.0.1:4222?request_timeout=5'));

try {
    $response = $nc->request('foo.bar', new Nats\Message('Hello World!'), timeout: 0.5); // overrides the default
} catch (Nats\Exception\RequestTimedOut $e) {
    dump($e->getMessage());
}
```

#### Request many

`Client::requestMany()` publishes one request and yields the replies of all responders as they arrive (scatter-gather, [ADR-47](https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-47.md)).
The iterator ends after `maxMessages` replies, once `timeout` has passed since the request (`Config::$requestTimeout` by default), when no reply arrives within `stall`
after the previous one, on the reply the `sentinel` matches, which is not yielded, or once the `cancellation` is requested (iterating then throws `Amp\CancelledException`).
The `stall` is counted between the replies only, as in ADR-47, since the first reply may take longer to come: it does not end the wait for a silent responder.
So at least one of the other conditions is required, otherwise `requestMany()` throws `\InvalidArgumentException` instead of possibly gathering forever.
If nobody listens to the subject, iterating throws `Nats\Exception\RequestHasNoResponders`.

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;

$nc = new Nats\Client(Nats\Config::default());

foreach (['eu', 'us', 'asia'] as $region) {
    $nc->subscribe('discovery', static function (Nats\Delivery $delivery) use ($region): void {
        $delivery->reply(new Nats\Message($region));
    });
}

// Whoever answers within a second.
foreach ($nc->requestMany('discovery', timeout: 1) as $reply) {
    dump("Found {$reply->message->payload}");
}

// The first two answers, and no more than 100ms of silence between them.
$replies = $nc->requestMany('discovery', maxMessages: 2, stall: 0.1, timeout: 1);
foreach ($replies as $reply) {
    dump("Found {$reply->message->payload}");
}

$nc->subscribe('report', static function (Nats\Delivery $delivery) use ($nc): void {
    if ($delivery->replyTo === null) {
        return;
    }

    foreach (['line 1', 'line 2', 'line 3'] as $line) {
        $nc->publish($delivery->replyTo, new Nats\Message($line));
    }

    $nc->publish($delivery->replyTo, new Nats\Message()); // end of stream
});

// A streamed reply, ended by an empty message.
$lines = $nc->requestMany(
    subject: 'report',
    sentinel: static fn(Nats\Delivery $delivery): bool => ($delivery->message->payload ?? '') === '',
    timeout: 5,
);
foreach ($lines as $line) {
    dump($line->message->payload);
}

$nc->drain();
```

#### Auto-unsubscribe

With `max`, the server removes the subscription after delivering that many messages. The subscription then completes once the handler has processed them:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;

$nc = new Nats\Client(Nats\Config::default());

$subscription = $nc->subscribe('foo.bar', static function (Nats\Delivery $delivery): void {
    dump("Received message {$delivery->message->payload}");
}, max: 2);

foreach (['1', '2', '3'] as $payload) {
    $nc->publish('foo.bar', new Nats\Message($payload)); // the third one is not delivered
}

$subscription->awaitCompletion();

// The same for an iterator: the loop ends after the third message.
$deliveries = $nc->subscribeIterator('foo.baz', max: 3);

$nc->drain();
```

#### Slow consumers

The messages a subscription has received, but its handler has not taken yet, are pending. By default a subscription keeps at most 524288 pending messages
and 64 MiB of their payload, like nats.go does; `pendingLimits` of `subscribe()` and `subscribeIterator()` changes that. The messages beyond the limits are dropped, and `Nats\Exception\SlowConsumer` with the subscription
is passed to the error callback, once until the handler catches up.

The client never stops reading the connection for a slow handler, so that it keeps answering the server's pings, the replies to the requests
the handler makes and the messages of the other subscriptions. Hence the pending limits are what bounds the memory the pending messages take,
and `PendingLimits::unlimited()` lets them grow with no bound.

The `bufferSize` of `subscribeIterator()` and `requestMany()` is a different thing: it is how many messages the iterator holds ahead of the consumer,
they are not pending anymore. A message that does not fit stays pending until the consumer takes one, so the pending limits are reached only behind a full buffer.

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;

$nc = new Nats\Client(Nats\Config::default());

$nc->onError(static function (\Throwable $e): void {
    if ($e instanceof Nats\Exception\SlowConsumer && $e->subscription !== null) {
        $stats = $e->subscription->stats();
        dump("{$e->subscription->subject}: {$stats->pendingMessages} pending, {$stats->dropped} dropped");
    }
});

$subscription = $nc->subscribe(
    subject: 'metrics.>',
    handler: static function (Nats\Delivery $delivery): void {
        // a slow handler
    },
    pendingLimits: new Nats\PendingLimits(messages: 10_000, bytes: null), // null for no limit
);

// The same for an iterator, here without any limits.
$deliveries = $nc->subscribeIterator('events.>', pendingLimits: Nats\PendingLimits::unlimited());

$stats = $subscription->stats();
dump($stats->pendingMessages, $stats->pendingBytes, $stats->delivered, $stats->dropped);
```

#### Server errors

The errors the server answers a particular call with are thrown from that call: `Nats\Exception\AuthorizationViolation` from the first call over a new connection,
`Nats\Exception\PermissionViolated` from `publish()` and `subscribe()` in verbose mode (`?verbose=true`). In non-verbose mode the server does not acknowledge
commands, so a subscription permission violation fails that subscription (`Subscription::awaitCompletion()` throws), and the rest is passed to the error callback:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;

$nc = new Nats\Client(Nats\Config::default());

$nc->onError(static function (\Throwable $e): void {
    // e.g. Nats\Exception\PermissionViolated for a publish to a subject the user is not allowed to publish to,
    // Nats\Exception\SlowConsumer, after which the server closes the connection,
    // or Nats\Exception\SlowConsumer with a subscription that drops messages, see Slow consumers
    dump($e->getMessage());
});
```

A fatal error makes the server close the connection: the subscriptions fail with it, it is passed to the error callback, and the next call connects again.
A call interrupted by the close throws `Nats\Exception\ConnectionWasClosed` with the server's error as the previous exception when the server sent one:

```php
try {
    $nc->publish('events.happens', new Nats\Message($payload));
} catch (Nats\Exception\ConnectionWasClosed $e) {
    dump($e->getPrevious()); // e.g. Nats\Exception\MaximumPayloadViolation for a payload over the limit of the user's account
}
```

#### Validation

Subjects and names are checked before anything is sent, following the rules of nats-server and nats.go:

- A subject is non-empty, without whitespace and empty tokens (a leading or trailing `.`, `..`): `Nats\Exception\InvalidSubject`.
  `publish()`, `request()` and reply-to subjects must not contain the wildcards `*` and `>`, `subscribe()` and stream, filter and micro endpoint subjects
  may contain `*` as a whole token and `>` as the last token. A wildcard inside a token, as in `foo*`, is a literal character.
- A queue group is non-empty and without whitespace: `Nats\Exception\InvalidSubject`.
- A stream, consumer or counter name is non-empty, at most 255 bytes, without `.`, `*`, `>`, `/`, `\`, whitespace and control characters: `Nats\Exception\InvalidName`.
- A priority group of a pull consumer matches `^[a-zA-Z0-9/_=-]{1,16}$`, as nats-server requires: `Nats\Exception\InvalidName`.
- A key-value or object store bucket name matches `^[a-zA-Z0-9_-]+$`: `Nats\Exception\InvalidBucketName`.
- A key-value key matches `^[-/_=.a-zA-Z0-9]+$` and is a valid subject; the keys to watch may contain wildcards: `Nats\Exception\InvalidKey`.
- An object name is non-empty: `Nats\Exception\ObjectIsInvalid`.
- A message (headers and payload together) is at most the `max_payload` the server announces, 1 MiB by default: `Nats\Exception\MaximumPayloadViolation`.
  The server would close the connection on a larger one. An account limit lower than that is known only to the server, which then closes the connection.
- A micro endpoint name matches `^[A-Za-z0-9_-]+$` (`Nats\Exception\InvalidName`), a group name is a subject without `>` (`Nats\Exception\InvalidSubject`).

## Nats JetStream

JetStream is the built-in NATS persistence system. The library provides both JetStream entity management (streams, consumers) and message publishing/consumption capabilities.

#### Publish

`JetStream::publish` sends a message to a subject bound to a stream and waits for the server acknowledgement (`Api\AckResponse`), which holds
the stream name and the sequence the message was stored with:
```php
<?php

declare(strict_types=1);

use Thesis\Nats;
use Thesis\Nats\JetStream\Api;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$js->createOrUpdateStream(new Api\StreamConfig(
    name: 'Orders',
    subjects: ['orders.*'],
));

$ack = $js->publish('orders.created', new Nats\Message('{"id": 1}'));

dump($ack->stream, $ack->seq); // "Orders", 1
```

#### Publish Options

`Nats\PublishOptions` sets the JetStream publish headers for you, so you don't have to build them by hand:

| Option                               | Header                                        | Meaning                                                                                                         |
|--------------------------------------|-----------------------------------------------|-----------------------------------------------------------------------------------------------------------------|
| `msgId`                              | `Nats-Msg-Id`                                 | Message id for deduplication within the stream `duplicateWindow`; a duplicate is acked with `duplicate: true`   |
| `expectedStream`                     | `Nats-Expected-Stream`                        | The message is rejected unless it is stored by this stream                                                      |
| `expectedLastSequence`               | `Nats-Expected-Last-Sequence`                 | The message is rejected unless the last sequence of the stream is this one                                      |
| `expectedLastSubjectSequence`        | `Nats-Expected-Last-Subject-Sequence`         | The message is rejected unless the last sequence of the subject is this one                                     |
| `expectedLastSubjectSequenceSubject` | `Nats-Expected-Last-Subject-Sequence-Subject` | Checks `expectedLastSubjectSequence` against this subject (wildcards allowed) instead of the published one      |
| `expectedLastMsgId`                  | `Nats-Expected-Last-Msg-Id`                   | The message is rejected unless the id of the last message of the stream is this one                             |
| `ttl`                                | `Nats-TTL`                                    | Per-message TTL, at least one second or `TimeSpan::fromSeconds(-1)` to never expire; requires `allowMessageTtl` |
| `retryAttempts`, `retryWait`         |                                               | How many times and how often to retry if there are no responders (defaults: 2 attempts, 250ms)                  |

The options replace the same headers set on the message by hand, other headers are kept. Invalid combinations are rejected when the options are created,
e.g. `expectedLastSubjectSequenceSubject` without `expectedLastSubjectSequence` or a TTL below one second.

Deduplication and optimistic concurrency control:
```php
<?php

declare(strict_types=1);

use Thesis\Nats;
use Thesis\Nats\JetStream\Api;
use Thesis\Time\TimeSpan;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$js->createOrUpdateStream(new Api\StreamConfig(
    name: 'Orders',
    subjects: ['orders.*'],
    duplicateWindow: TimeSpan::fromMinutes(2),
));

$ack = $js->publish('orders.created', new Nats\Message('{"id": 1}'), new Nats\PublishOptions(msgId: 'order-1'));
dump($ack->seq); // 1

// The same message id within the duplicate window is not stored again.
$ack = $js->publish('orders.created', new Nats\Message('{"id": 1}'), new Nats\PublishOptions(msgId: 'order-1'));
dump($ack->seq, $ack->duplicate); // 1, true

// Stored only if nobody has written to the stream since the message 1.
$js->publish('orders.paid', new Nats\Message('{"id": 1}'), new Nats\PublishOptions(
    expectedStream: 'Orders',
    expectedLastSequence: 1,
));

try {
    $js->publish('orders.paid', new Nats\Message('{"id": 2}'), new Nats\PublishOptions(expectedLastSequence: 1));
} catch (Nats\Exception\WrongLastSequence) {
    // somebody published in between: re-read the state and try again
}
```

Check the last sequence of another subject, e.g. of all the `orders.*` subjects, and expire the message after an hour (the stream must be created with `allowMessageTtl: true`):
```php
$js->publish('orders.cancelled', new Nats\Message('{"id": 1}'), new Nats\PublishOptions(
    expectedLastSubjectSequence: 2,
    expectedLastSubjectSequenceSubject: 'orders.*',
    ttl: TimeSpan::fromHours(1),
));
```

When the stream is unavailable for a moment, e.g. while its leader is being elected, the server reports that there are no responders.
The publish is retried `retryAttempts` times every `retryWait`, after that `Nats\Exception\RequestHasNoResponders` is thrown.
Pass a cancellation to limit the whole publish including the retries:
```php
use Amp\TimeoutCancellation;

$ack = $js->publish(
    'orders.created',
    new Nats\Message('{"id": 1}'),
    new Nats\PublishOptions(
        retryAttempts: 10,
        retryWait: TimeSpan::fromMilliseconds(500),
    ),
    new TimeoutCancellation(5),
);
```

#### Fetch Batch

You can request a specific number of messages at once using `PullConsumer::fetch` with `FetchConfig::batch(number_of_messages)`. For example:
```php
<?php

declare(strict_types=1);

use Thesis\Nats;
use Thesis\Nats\JetStream;

$consumer = $stream->createOrUpdateConsumer(...);

$batch = $consumer
    ->pulling()
    ->fetch(JetStream\FetchConfig::batch(10));

foreach ($batch as $delivery) {
    // handle message
    $delivery->ack();
}
```

You can also configure the batch retrieval timeout and heartbeat settings using `FetchConfig::maxWait` and `FetchConfig::heartbeat` parameters.

#### Fetch Bytes

If you want to retrieve a batch of a specific size in bytes, you can use `FetchConfig::bytes(byte_size)`:
```php
<?php

declare(strict_types=1);

use Thesis\Nats;
use Thesis\Nats\JetStream;

$consumer = $stream->createOrUpdateConsumer(...);

$batch = $consumer
    ->pulling()
    ->fetch(JetStream\FetchConfig::bytes(1024));

foreach ($batch as $delivery) {
    // handle message
    $delivery->ack();
}
```

And you can also configure the timeout and heartbeat settings.

#### Fetch Immediate

If you simply want to retrieve a batch of messages currently available in the stream, use `FetchConfig::immediate()`:

```php
<?php

declare(strict_types=1);

use Thesis\Nats;
use Thesis\Nats\JetStream;

$consumer = $stream->createOrUpdateConsumer(...);

$batch = $consumer
    ->pulling()
    ->fetch(JetStream\FetchConfig::immediate());

foreach ($batch as $delivery) {
    // handle message
    $delivery->ack();
}
```

#### Push Consumer

NATS offers two message delivery models: **pull** and **push**. Although the NATS documentation [recommends](https://docs.nats.io/nats-concepts/jetstream/consumers#dispatch-type-pull-push) the pull approach, for most PHP use cases push consumers are often preferable.
Similar to RabbitMQ, push consumers can be configured to avoid negative impacts on both the consumers and the messages themselves.
Key configuration parameters include `maxAckPending`, which limits the number of unacknowledged messages NATS will deliver before waiting (analogous to `prefetch count` in RabbitMQ),
and the `ackPolicy`. This setup allows you to scale message processing by increasing the number of consumers and distribute messages among them more evenly.

For example, if you want to process messages one by one with explicit acknowledgments, you would set `maxAckPending=1` and `ackPolicy=explicit`.

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Api;
use Thesis\Time\TimeSpan;

$consumer = $stream->createOrUpdateConsumer(new Api\ConsumerConfig(
    durableName: 'EventPushConsumer',
    deliverSubject: 'push-consumer-delivery',
    ackPolicy: Api\AckPolicy::Explicit,
    idleHeartbeat: TimeSpan::fromSeconds(5),
    maxAckPending: 1,
));

$subscription = $consumer->push(
    static function (Nats\JetStream\Delivery $delivery): void {
        dump($delivery->message->payload);
        $delivery->ack();
    },
);
```

Additionally, for push consumers, the `deliverSubject` parameter is mandatory, as it is this very parameter that distinguishes the push model from pull.

If you want to distribute messages from a single consumer across different subscriptions, you should use the `deliverGroup` parameter.
This enables you to scale processing across multiple consumer instances. This parameter functions identically to `queueGroup` in NATS Core.

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Api;
use Thesis\Time\TimeSpan;

$consumer = $stream->createOrUpdateConsumer(new Api\ConsumerConfig(
    durableName: 'EventPushConsumer',
    deliverSubject: 'push-consumer-delivery',
    deliverGroup: 'testing',
    ackPolicy: Api\AckPolicy::Explicit,
    idleHeartbeat: TimeSpan::fromSeconds(5),
    maxAckPending: 1,
));
```

Please refer to the push consumer configuration [documentation](https://docs.nats.io/nats-concepts/jetstream/consumers#push-specific) to understand the purpose of each parameter.

#### Pull Consumer

Additionally, you can use `PullConsumer::consume`. This method handles message buffering, periodically requests the server and monitors timeouts to automatically request the next message batch.

Just like with push consumers, you can call `pull` directly on the `Consumer` object:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Api;
use Thesis\Time\TimeSpan;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$consumer = $stream->createOrUpdateConsumer(new Api\ConsumerConfig(
    durableName: 'EventPullConsumer',
    ackPolicy: Api\AckPolicy::Explicit,
));

$subscription = $consumer->pull(
    static function (Nats\JetStream\Delivery $delivery): void {
        dump($delivery->message->payload);
        $delivery->ack();
    },
);
```

However, if you want to read messages with multiple consumers within the same process, you need to create a `PullConsumer` using `Consumer::pulling()`, and then create multiple consumer instances:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Api;
use Thesis\Time\TimeSpan;
use function Amp\trapSignal;

$consumer = $stream->createOrUpdateConsumer(new Api\ConsumerConfig(
    durableName: 'EventPullConsumer',
    ackPolicy: Api\AckPolicy::Explicit,
));

$consumer = $consumer->pulling();

$consumer->consume(
    static function (Nats\JetStream\Delivery $delivery): void {
        dump("Consumer#1: {$delivery->message->payload}");
        $delivery->ack();
    },
);

$consumer->consume(
    static function (Nats\JetStream\Delivery $delivery): void {
        dump("Consumer#2: {$delivery->message->payload}");
        $delivery->ack();
    },
);

trapSignal([\SIGINT, \SIGTERM]);

$consumer->drain();
```

To stop all subscriptions, use `Consumer::drain()` or `Consumer::stop()`.

You can control the number of requested messages per batch or the batch size using the `PullConsumeConfig::maxMessages` and `PullConsumeConfig::maxBytes` parameters respectively.
However, only one of these parameters can be set at a time.

```php
<?php

declare(strict_types=1);

use Thesis\Nats\JetStream;;

$consumer->consume(
    static function (Nats\JetStream\Delivery $delivery): void {},
    new JetStream\PullConsumeConfig(
        maxMessages: 1_000,
    ),
);
```

#### Get message

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Api\StreamConfig;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$js->deleteStream('EventStream');

$stream = $js->createStream(new StreamConfig(
    name: 'EventStream',
    description: 'Application events',
    subjects: ['events.*'],
));

for ($i = 0; $i < 5; ++$i) {
    $js->publish(
        subject: 'events.payment_rejected',
        message: new Nats\Message(
            payload: "Message#{$i}",
            headers: (new Nats\Headers())
                ->with(Nats\Header\MsgId::header(), "id:{$i}"),
        ),
    );
}

dump($stream->getLastMessageForSubject('events.payment_rejected')?->payload);

$nc->drain();
```

## NATS Key Value Store

JetStream, the persistence layer of NATS, not only allows for the higher qualities of service and features associated with 'streaming', but it also enables some functionalities not found in messaging systems like Key Value Store.

#### Store key values

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\KeyValue\BucketConfig;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$kv = $js->createOrUpdateKeyValue(new BucketConfig(
    bucket: 'configs',
));

$kv->put('app.env', 'prod');
$kv->put('database.dsn', 'mysql:host=127.0.0.1;port=3306');

dump(
    $kv->get('app.env')?->value,
    $kv->get('database.dsn')?->value,
);

$nc->drain();
```

#### Create and update buckets

`createOrUpdateKeyValue()` creates the bucket or updates its config. `createKeyValue()` only creates it and fails with `StreamNameAlreadyInUse` if the bucket exists with another config,
`updateKeyValue()` only updates it and fails with `StreamNotFound` if there is no bucket.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\Exception\StreamNotFound;
use Thesis\Nats\JetStream\KeyValue\BucketConfig;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$js->deleteKeyValue('configs');

try {
    $js->updateKeyValue(new BucketConfig(bucket: 'configs'));
} catch (StreamNotFound) {
    dump('There is no bucket to update yet.');
}

$js->createKeyValue(new BucketConfig(bucket: 'configs'));

$kv = $js->updateKeyValue(new BucketConfig(bucket: 'configs', history: 5));

dump($kv->status()->history); // 5

$nc->drain();
```

#### Create, update and expire keys

`create()` puts the value only if the key has no value: it was never put, or it was deleted or purged. `update()` puts the value only if the latest revision of the key is the given one.
Both fail with `WrongLastSequence` otherwise. `put()`, `create()` and `update()` take a TTL of the entry, which requires the bucket to have `limitMarkerTtl`:
once the entry expires, the server places a marker that `get()` returns as a purged entry, and the key can be created again.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\Exception\WrongLastSequence;
use Thesis\Nats\JetStream\KeyValue\BucketConfig;
use Thesis\Time\TimeSpan;
use function Amp\delay;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$kv = $js->createOrUpdateKeyValue(new BucketConfig(
    bucket: 'locks',
    limitMarkerTtl: TimeSpan::fromMinutes(1),
));

$revision = $kv->create('job.report', 'worker-1', ttl: TimeSpan::fromSeconds(10));

try {
    $kv->create('job.report', 'worker-2');
} catch (WrongLastSequence) {
    dump('The lock is taken.');
}

// Prolong the lock, only if nobody has changed it meanwhile.
$revision = $kv->update('job.report', $revision, 'worker-1', ttl: TimeSpan::fromSeconds(10));

// Release the lock: a deleted key can be created again.
$kv->delete('job.report', $revision);
$kv->create('job.report', 'worker-2');

$kv->put('job.cleanup', 'worker-3', ttl: TimeSpan::fromSeconds(1));
delay(2);
dump($kv->get('job.cleanup')?->state); // EntryState::Purged

$nc->drain();
```

#### Keys and history

`keys()` returns the keys that have a value, optionally only the ones matching the filters, `history()` returns every stored revision of a key or of the keys matching a pattern, delete and purge markers included. Both read through a short-lived watch; the cancellation they take limits the whole read, the server requests included.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\KeyValue\BucketConfig;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$kv = $js->createOrUpdateKeyValue(new BucketConfig(
    bucket: 'configs',
    history: 10,
));

$kv->put('app.env', 'prod');
$kv->put('app.env', 'dev');
$kv->put('app.debug', '1');
$kv->put('database.dsn', 'mysql:host=127.0.0.1;port=3306');
$kv->delete('app.debug');

dump($kv->keys()); // ['app.env', 'database.dsn']
dump($kv->keys('app.*')); // ['app.env']
dump($kv->keys(['app.>', 'database.dsn'])); // ['app.env', 'database.dsn']

foreach ($kv->history('app.env') as $entry) {
    dump("{$entry->revision}: {$entry->value}");
}

$nc->drain();
```

#### Bucket status

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\KeyValue\BucketConfig;
use Thesis\Time\TimeSpan;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$kv = $js->createOrUpdateKeyValue(new BucketConfig(
    bucket: 'configs',
    history: 5,
    ttl: TimeSpan::fromHours(1),
    compression: true,
));

$kv->put('app.env', 'prod');

$status = $kv->status();

dump(
    $status->bucket,       // configs
    $status->values,       // the number of stored revisions, markers included
    $status->history,      // 5
    $status->ttl?->toSeconds(), // 3600
    $status->bytes,
    $status->backingStore, // JetStream
    $status->compressed,   // true
);

// The info of the stream behind the bucket.
dump($status->info->config->maxBytes);

$nc->drain();
```

#### Purge deletes

`delete()` and `purge()` leave a marker, so that watches see the key is gone. `purgeDeletes()` removes the revisions of the deleted and purged keys and the markers older than the given age (30 minutes by default),
the recent markers are kept. Pass zero to remove all markers.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\KeyValue\BucketConfig;
use Thesis\Time\TimeSpan;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$kv = $js->createOrUpdateKeyValue(new BucketConfig(
    bucket: 'configs',
    history: 10,
));

$kv->put('app.env', 'prod');
$kv->put('app.env', 'dev');
$kv->delete('app.env');

$kv->purgeDeletes();
dump(\count($kv->history('app.env'))); // 1, the recent delete marker

$kv->purgeDeletes(TimeSpan::fromSeconds(0));
dump(\count($kv->history('app.env'))); // 0

$nc->drain();
```

#### Watch KV

`watch()` first calls the handler with the latest entry of every watched key, deleted keys included, and then with every change. It returns a `Watcher`:
`awaitInitialValues()` returns once the handler has been called for every entry the bucket had when the watch started, so the watcher is up to date from then on.
`stop()` stops the watch and deletes its consumer.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\KeyValue\BucketConfig;
use Thesis\Nats\JetStream\KeyValue\Entry;
use function Amp\trapSignal;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$kv = $js->createOrUpdateKeyValue(new BucketConfig(
    bucket: 'configs',
));

$kv->put('app.env', 'prod');

$watcher = $kv->watch(static function (Entry $entry): void {
    dump("Config key {$entry->key} is {$entry->value}, revision {$entry->revision}");
});

$watcher->awaitInitialValues();
dump('The current configs are loaded.');

$kv->put('app.env', 'dev');
$kv->put('database.dsn', 'mysql:host=127.0.0.1;port=3306');

trapSignal([\SIGTERM, \SIGINT]);

$watcher->stop();

$nc->drain();
```

`WatchConfig` changes what is delivered:
- `includeHistory` delivers every stored revision of the watched keys, not only the latest ones;
- `updatesOnly` delivers only the changes made after the watch has started, `awaitInitialValues()` returns at once;
- `metaOnly` delivers entries without values, `Entry::$size` still has the size of the value;
- `ignoreDeletes` does not pass delete and purge markers to the handler;
- `resumeFromRevision` delivers every revision starting from the given one, e.g. to continue a watch from the last handled revision.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\KeyValue\BucketConfig;
use Thesis\Nats\JetStream\KeyValue\Entry;
use Thesis\Nats\JetStream\KeyValue\WatchConfig;
use function Amp\delay;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$kv = $js->createOrUpdateKeyValue(new BucketConfig(
    bucket: 'configs',
    history: 10,
));

$kv->put('app.env', 'prod');
$kv->put('app.env', 'dev');

$watcher = $kv->watch(
    static function (Entry $entry): void {
        dump("{$entry->key}@{$entry->revision}: {$entry->value}");
    },
    keys: 'app.*',
    config: new WatchConfig(includeHistory: true, ignoreDeletes: true),
);

$watcher->awaitInitialValues();
$watcher->stop();

$watcher = $kv->watch(
    static function (Entry $entry): void {
        dump("{$entry->key} changed to {$entry->value}");
    },
    config: new WatchConfig(updatesOnly: true),
);

$kv->put('app.env', 'test');

delay(1);
$watcher->stop();

$nc->drain();
```

#### Mirrors and sources

A bucket with a `mirror` is a read-only copy of another bucket: it gets every entry of the origin, and a `put()` to the mirror goes to the origin.
A bucket with `sources` gets the entries of the source buckets next to its own ones. The sources and the mirror are named by the bucket name.

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Api\StreamSource;
use Thesis\Nats\JetStream\KeyValue\BucketConfig;
use function Amp\delay;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$eu = $js->createOrUpdateKeyValue(new BucketConfig(bucket: 'configs-eu'));
$us = $js->createOrUpdateKeyValue(new BucketConfig(bucket: 'configs-us'));

$replica = $js->createOrUpdateKeyValue(new BucketConfig(
    bucket: 'configs-eu-replica',
    mirror: new StreamSource('configs-eu'),
));

$all = $js->createOrUpdateKeyValue(new BucketConfig(
    bucket: 'configs-all',
    sources: [
        new StreamSource('configs-eu'),
        new StreamSource('configs-us'),
    ],
));

$eu->put('eu.dsn', 'mysql:host=10.0.0.1');
$us->put('us.dsn', 'mysql:host=10.1.0.1');

delay(1); // mirrors and sources are copied asynchronously

dump(
    $replica->get('eu.dsn')?->value,
    $all->get('eu.dsn')?->value,
    $all->get('us.dsn')?->value,
);

$nc->drain();
```

## NATS Object Store

JetStream, the persistence layer of NATS, not only allows for the higher qualities of service and features associated with 'streaming', but it also enables some functionalities not found in messaging systems like Object Store.

#### Store objects in the buckets

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\ObjectStore\ObjectMeta;
use Thesis\Nats\JetStream\ObjectStore\ResourceReader;
use Thesis\Nats\JetStream\ObjectStore\StoreConfig;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$js->deleteObjectStore('code');

$store = $js->createOrUpdateObjectStore(new StoreConfig(
    store: 'code',
));

$handle = fopen(__DIR__.'/app.php', 'r') ?? throw new \RuntimeException('Failed to open file.');

$store->put(new ObjectMeta(name: 'app.php'), new ResourceReader($handle));

fclose($handle);

$store->put(new ObjectMeta('config.php'), '<?php return [];');

dump(
    (string) $store->get('app.php'),
    (string) $store->get('config.php'),
);

$nc->drain();
```

#### Watch Object Store

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\ObjectStore\ObjectInfo;
use Thesis\Nats\JetStream\ObjectStore\ObjectMeta;
use Thesis\Nats\JetStream\ObjectStore\StoreConfig;
use function Amp\delay;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$js->deleteObjectStore('code');

$store = $js->createOrUpdateObjectStore(new StoreConfig(
    store: 'code',
));

$subscription = $store->watch(static function (ObjectInfo $info): void {
    dump("New object {$info->name} in the bucket {$info->bucket} at size {$info->size} bytes");
});

$store->put(new ObjectMeta('config.php'), '<?php return [];');
$store->put(new ObjectMeta('snippet.php'), '<?php echo 1 + 1;');

delay(0.5);

$subscription->stop();

$nc->drain();
```

## NATS CRDT

Distributed Counter CRDT. A Stream can opt in to supporting Counters which will allow any subject to be a counter. All subjects in the stream must be counters.
See [ADR-49](https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-49.md) for details.

#### Add Counter

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Counter\CounterConfig;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$counter = $js->createOrUpdateCounter(new CounterConfig(
    name: 'atomics',
));

dump($counter->add('x', 1)); // 1
dump($counter->add('x', 2)); // 3
```

#### Get Counter

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Counter\CounterConfig;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$counter = $js->createOrUpdateCounter(new CounterConfig(
    name: 'atomics',
));

dump($counter->add('x', 1)); // 1
dump($counter->get('x')?->value); // 1
```

#### Get Counters

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Counter\CounterConfig;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$jetstream->deleteCounter('atomics');

$counter = $js->createOrUpdateCounter(new CounterConfig(
    name: 'atomics',
));

$counter->add('x', 1);
$counter->add('y', 1);
$counter->add('z', 1);

foreach ($counter->getMultiple() as $entry) {
    echo "{$entry->subject}: {$entry->value}\n";
}
```

## NATS Message Scheduler

Delayed Message Scheduling. The `AllowMsgSchedules` stream configuration option allows the scheduling of messages. Users can use this feature for delayed publishing/scheduling of messages.
See [ADR-51](https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-51.md) for details.

#### Single scheduled message

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\Header;
use Thesis\Nats\JetStream\Api\AckPolicy;
use Thesis\Nats\JetStream\Api\ConsumerConfig;
use Thesis\Nats\JetStream\Api\StreamConfig;
use Thesis\Nats\JetStream\Api\DeliverPolicy;
use function Amp\trapSignal;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$stream = $js->createStream(new StreamConfig(
    name: 'RecurrentsStream',
    subjects: [
        'recurrents',
        'scheduler.recurrents.*',
    ],
    allowMsgSchedules: true,
));

$js->publish('scheduler.recurrents.1', new Nats\Message(
    payload: '{"id":1}',
    headers: (new Nats\Headers())
        ->with(Header\Schedule::Header, new \DateTimeImmutable('+5 seconds'))
        ->with(Header\ScheduleTarget::header(), 'recurrents'),
));

$subscription = $stream
    ->createOrUpdateConsumer(new ConsumerConfig(
        durableName: 'RecurrentsConsumer',
        deliverPolicy: DeliverPolicy::New,
        ackPolicy: AckPolicy::None,
        filterSubjects: ['recurrents'],
    ))
    ->pull(static function (Nats\JetStream\Delivery $delivery): void {
        dump([
            $delivery->message->payload,
            $delivery->message->headers?->get(Header\Scheduler::header()),
            $delivery->message->headers?->get(Header\ScheduleNext::header()),
        ]);
    });

trapSignal([\SIGINT, \SIGTERM]);

$subscription->awaitCompletion();
```

## NATS JetStream Batch Publishing

The `AllowAtomicPublish` stream configuration option allows to atomically publish N messages into a stream. See [ADR-50](https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-50.md) for details.

#### Publish using `PublishBatch`

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Api\StreamConfig;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$stream = $js->createStream(new StreamConfig(
    name: 'Batches',
    description: 'Batch Stream',
    subjects: ['batch.*'],
    allowAtomicPublish: true,
));

$batch = $js->createPublishBatch();

for ($i = 0; $i < 999; ++$i) {
    $batch->publish('batch.orders', new Nats\Message("Order#{$i}"));
}

$batch->publish('batch.orders', new Nats\Message('Order#1000'), new Nats\PublishBatchOptions(commit: true));
```

#### Publish batch using JetStream

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Api\StreamConfig;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$stream = $js->createStream(new StreamConfig(
    name: 'Batches',
    description: 'Batch Stream',
    subjects: ['batch.*'],
    allowAtomicPublish: true,
));

$js->publishBatch('batch.orders', [
    new Nats\Message('Order#1'),
    new Nats\Message('Order#2'),
    new Nats\Message('Order#3'),
]);
```

## Nats Service Api

This is implementation of [ADR-32](https://github.com/nats-io/nats-architecture-and-design/blob/main/adr/ADR-32.md).
The core of the `Micro` component is the `Service`. A `Service` aggregates endpoints for handling application logic. Services are named and versioned. You create a Service using the `Client::createService()`, passing in the `Service` configuration.

#### Micro Service

```php
<?php

declare(strict_types=1);

use Thesis\Nats;
use Thesis\Nats\Micro;

require __DIR__ . '/vendor/autoload.php';

$nc = new Nats\Client(
    Nats\Config::default(),
);

$srv = $nc->createService(new Micro\ServiceConfig('EchoService', '1.0.0'));
```

#### Service endpoints

After a service is created, endpoints can be added. An endpoint name is an identifier matching `^[A-Za-z0-9_-]+$`, it is reported in the service info and stats.
The subject the endpoint listens on is set separately and defaults to the name.

```php
<?php

declare(strict_types=1);

use Thesis\Nats;
use Thesis\Nats\Micro;

require __DIR__ . '/vendor/autoload.php';

$nc = new Nats\Client(
    Nats\Config::default(),
);

$srv = $nc->createService(new Micro\ServiceConfig('EchoService', '1.0.0'));

$srv
    ->addEndpoint(new Micro\EndpointConfig('echo', subject: 'srv.echo'), static function (Micro\Request $request): void {
        $request->respond(new Micro\Response($request->data));
    });

dump($nc->request('srv.echo', new Nats\Message('ping'))->message->payload);
```

The subject may contain wildcards: `*` as a whole token and `>` as the last token.

```php
<?php

declare(strict_types=1);

use Thesis\Nats;
use Thesis\Nats\Micro;

require __DIR__ . '/vendor/autoload.php';

$nc = new Nats\Client(
    Nats\Config::default(),
);

$srv = $nc->createService(new Micro\ServiceConfig('EchoService', '1.0.0'));

$srv
    ->addEndpoint(new Micro\EndpointConfig('echo', subject: 'srv.echo.*'), static function (Micro\Request $request): void {
        $request->respond(new Micro\Response($request->subject));
    });

dump($nc->request('srv.echo.x', new Nats\Message('ping'))->message->payload);
```

#### Groups

Endpoints can also be aggregated using groups. A group represents a common subject prefix used by all endpoints associated with it.
The prefix is prepended to the endpoint's subject (to its name, if no subject is set), the endpoint name stays the same.

```php
<?php

declare(strict_types=1);

use Thesis\Nats;
use Thesis\Nats\Micro;

require __DIR__ . '/vendor/autoload.php';

$nc = new Nats\Client(
    Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222'),
);

$nc
    ->createService(new Micro\ServiceConfig('EchoService', '1.0.0'))
        ->addGroup(new Micro\GroupConfig('srv.api'))
            ->addGroup(new Micro\GroupConfig('v1'))
                ->addEndpoint(new Micro\EndpointConfig('echo'), static function (Micro\Request $request): void {
                    $request->respond(new Micro\Response($request->data));
                });

dump($nc->request('srv.api.v1.echo', new Nats\Message('ping'))->message->payload);
```

#### Service errors

`Request::error()` replies with the `Nats-Service-Error-Code` and `Nats-Service-Error` headers and an optional payload, and counts the request as failed in the endpoint stats (`num_errors`, `last_error`).

```php
<?php

declare(strict_types=1);

use Thesis\Nats;
use Thesis\Nats\Micro;

require __DIR__ . '/vendor/autoload.php';

$nc = new Nats\Client(
    Nats\Config::default(),
);

$srv = $nc->createService(new Micro\ServiceConfig('UserService', '1.0.0'));

$srv
    ->addEndpoint(new Micro\EndpointConfig('find', subject: 'users.find'), static function (Micro\Request $request): void {
        $request->error(404, 'User not found', '{"id": 42}');
    });

$response = $nc->request('users.find', new Nats\Message('42'));

dump(
    $response->message->headers?->get(Nats\Header\ServiceErrorCode::header()), // 404
    $response->message->headers?->get(Nats\Header\ServiceError::header()), // User not found
    $response->message->payload, // {"id": 42}
);
```

A handler can also throw a `Micro\ServiceError` to reply with its code, description and payload.
Any other exception thrown before the handler has replied is answered with code `500` and the exception message as the description, so the requester does not wait for its timeout.
Either way the error the requester got is counted in the endpoint stats: `lastError` is that `Micro\ServiceError`, with the original exception as the previous one, and `last_error` in `$SRV.STATS` reads e.g. `500:<message>`.

```php
<?php

declare(strict_types=1);

use Thesis\Nats;
use Thesis\Nats\Micro;

require __DIR__ . '/vendor/autoload.php';

$nc = new Nats\Client(
    Nats\Config::default(),
);

$srv = $nc->createService(new Micro\ServiceConfig('UserService', '1.0.0'));

$srv
    ->addEndpoint(new Micro\EndpointConfig('create', subject: 'users.create'), static function (Micro\Request $request): void {
        throw new Micro\ServiceError(409, 'User already exists');
    });

$response = $nc->request('users.create', new Nats\Message('{"name": "John"}'));

dump($response->message->headers?->get(Nats\Header\ServiceErrorCode::header())); // 409
```

#### Custom stats

The `statsHandler` returns the custom `data` of every endpoint's stats. The value is encoded as JSON in the `$SRV.STATS` responses and is available in `Service::stats()` as is.

```php
<?php

declare(strict_types=1);

use Thesis\Nats;
use Thesis\Nats\Micro;

require __DIR__ . '/vendor/autoload.php';

$nc = new Nats\Client(
    Nats\Config::default(),
);

$srv = $nc->createService(new Micro\ServiceConfig(
    name: 'EchoService',
    version: '1.0.0',
    statsHandler: static fn(Micro\EndpointInfo $endpoint): array => ['endpoint' => $endpoint->name, 'memory' => memory_get_usage()],
));

$srv->addEndpoint(new Micro\EndpointConfig('echo'), static function (Micro\Request $request): void {
    $request->respond(new Micro\Response($request->data));
});

dump($srv->stats()->endpoints[0]->data);
```

#### Stopping a service

`Service::stop()` unsubscribes the endpoints at once and drops the requests they have not handled yet, `Service::drain()` unsubscribes them and waits until the requests already received are handled.
The service is also stopped when its subscriptions are completed from the outside: when the client is stopped, or when the server rejects a subscription, e.g. on a permissions violation.
Once the service has started stopping, `addEndpoint()` (on the service or a group) throws `Nats\Exception\ServiceAlreadyStopped`.
`Service::$state` transitions from `Micro\Service\State::Alive` to `Stopping` as soon as shutdown starts, and to `Stopped` once shutdown completes.
New endpoints and groups cannot be added while the service is stopping or after it has stopped: these calls throw `ServiceAlreadyStopped`.

The `doneHandler` is called asynchronously once the service has stopped, for any of these reasons. The `errorHandler` is called asynchronously with the error of a failed service subscription, after which the service is stopped.
Subscription failures are reported once, for the first failed subscription only: e.g. a permissions violation fails all the `$SRV` subscriptions at once, but the service is already stopping after the first one (nats.go calls its handler for every failed subscription).
Exceptions from `doneHandler` are also passed to `errorHandler`. If no `errorHandler` is configured, or it throws an exception itself, the exception goes to the Revolt event loop error handler (`EventLoop::setErrorHandler()`).
Callback failures do not change the service's `Stopped` state. Callbacks may call `stop()` or `drain()` again.

```php
<?php

declare(strict_types=1);

use Thesis\Nats;
use Thesis\Nats\Micro;

require __DIR__ . '/vendor/autoload.php';

$nc = new Nats\Client(
    Nats\Config::default(),
);

$srv = $nc->createService(new Micro\ServiceConfig(
    name: 'EchoService',
    version: '1.0.0',
    doneHandler: static function (Micro\Service $srv): void {
        dump("Service {$srv->info()->identity->id} has stopped.");
    },
    errorHandler: static function (Micro\Service $srv, \Throwable $e): void {
        dump("Service {$srv->info()->identity->id} has failed: {$e->getMessage()}");
    },
));

$srv->addEndpoint(new Micro\EndpointConfig('echo'), static function (Micro\Request $request): void {
    $request->respond(new Micro\Response($request->data));
});

$srv->drain();
```

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
