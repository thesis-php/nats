# Thesis Nats

Pure non-blocking (fiber based) strictly typed full-featured PHP driver for NATS.

## Features
- [NATS Core](https://docs.nats.io/nats-concepts/core-nats) 
  - [Publish-Subscribe](#pub-sub)
  - [Queues](#queues)
  - [Request-Reply](#request-reply)
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
  - [Watch KV](#watch-kv)
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
    // or Nats\Exception\SlowConsumer, after which the server closes the connection
    dump($e->getMessage());
});
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

#### Watch KV

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\KeyValue\BucketConfig;
use function Amp\trapSignal;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$js->deleteKeyValue('configs');

$kv = $js->createOrUpdateKeyValue(new BucketConfig(
    bucket: 'configs',
));

$subscription = $kv->watch(static function (Nats\JetStream\KeyValue\Entry $entry): void {
    dump("Config key {$entry->key} value changed to {$entry->value}");
});

$kv->put('app.env', 'prod');
$kv->put('database.dsn', 'mysql:host=127.0.0.1;port=3306');

trapSignal([\SIGTERM, \SIGINT]);

$subscription->stop();

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

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
