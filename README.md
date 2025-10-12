# Thesis Nats

Pure non-blocking (fiber based) strictly typed full-featured PHP driver for NATS.

## Features
- [NATS Core](https://docs.nats.io/nats-concepts/core-nats) 
  - [Publish-Subscribe](#pub-sub)
  - [Queues](#queues)
  - [Request-Reply](#request-reply)
- [NATS JetStream](https://docs.nats.io/nats-concepts/jetstream)
  - [Consume](#consume)
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

$nats = new Nats\Client(Nats\Config::default());

$nats->subscribe('foo.*', static function (Nats\Delivery $delivery): void {
    dump("Received message {$delivery->message->payload} for consumer#1");
});

$nats->subscribe('foo.>', static function (Nats\Delivery $delivery): void {
    dump("Received message {$delivery->message->payload} for consumer#2");
});

$sid = $nats->subscribe('foo.bar', static function (Nats\Delivery $delivery): void {
    dump("Received message {$delivery->message->payload} for consumer#3");
});

$nats->publish('foo.bar', new Nats\Message('Hello World!')); // visible for all consumers
$nats->publish('foo.baz', new Nats\Message('Hello World!')); // visible only for 1-2 consumers
$nats->publish('foo.bar.baz', new Nats\Message('Hello World!')); // visible only for 2 consumer

$nats->unsubscribe($sid);
$nats->publish('foo.bar', new Nats\Message('Hello World!')); // visible for 1-2 consumers

trapSignal([\SIGTERM, \SIGINT]);

$nats->disconnect();
```

#### Queues

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use function Amp\trapSignal;

$nats = new Nats\Client(Nats\Config::default());

$nats->subscribe(
    subject: 'foo.>',
    handler: static function (Nats\Delivery $delivery): void {
        dump("Received message {$delivery->message->payload} for consumer#1");
    },
    queueGroup: 'test',
);

$nats->subscribe(
    subject: 'foo.>',
    handler: static function (Nats\Delivery $delivery): void {
        dump("Received message {$delivery->message->payload} for consumer#2");
    },
    queueGroup: 'test',
);

$nats->subscribe(
    subject: 'foo.>',
    handler: static function (Nats\Delivery $delivery): void {
        dump("Received message {$delivery->message->payload} for consumer#3");
    },
    queueGroup: 'test',
);

$nats->publish('foo.bar', new Nats\Message('x'));
$nats->publish('foo.baz', new Nats\Message('y'));
$nats->publish('foo.bar.baz', new Nats\Message('z'));

trapSignal([\SIGTERM, \SIGINT]);

$nats->disconnect();
```

#### Request-reply

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;

$nats = new Nats\Client(Nats\Config::default());

$nats->subscribe('foo.>', static function (Nats\Delivery $delivery): void {
    dump("Received request {$delivery->message->payload}");
    $delivery->reply(new Nats\Message(strrev($delivery->message->payload ?? '')));
});

$response = $nats->request('foo.bar', new Nats\Message('Hello World!'));
dump("Received response {$response->message->payload}");

$nats->disconnect();
```

## Nats JetStream

JetStream is the built-in NATS persistence system. The library provides both JetStream entity management (streams, consumers) and message publishing/consumption capabilities.

#### Consume

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Api\AckPolicy;
use Thesis\Nats\JetStream\Api\ConsumerConfig;
use Thesis\Nats\JetStream\Api\StreamConfig;
use Thesis\Nats\JetStream\ConsumeConfig;
use Thesis\Time\TimeSpan;
use function Amp\async;
use function Amp\trapSignal;

$client = new Nats\Client(Nats\Config::default());
$js = $client->jetStream();

$js->deleteStream('EventStream');

$stream = $js->createStream(new StreamConfig(
    name: 'EventStream',
    description: 'Application events',
    subjects: ['events.*'],
));

$logConsumer = $stream->createConsumer(new ConsumerConfig(
    durableName: 'EventLog',
    ackPolicy: AckPolicy::None,
));

$logDeliveries = $logConsumer->consume(new ConsumeConfig(
    batch: 10,
    heartbeat: TimeSpan::fromSeconds(5),
));

async(static function () use ($logDeliveries): void {
    /** @var Nats\JetStream\Delivery $delivery */
    foreach ($logDeliveries as $delivery) {
        dump("Log event with ack=none: {$delivery->message->payload} ({$delivery->subject})");
    }
});

$handleConsumer = $stream->createConsumer(new ConsumerConfig(
    durableName: 'EventHandle',
    ackPolicy: AckPolicy::Explicit,
));

$handleDeliveries = $handleConsumer->consume(new ConsumeConfig(
    batch: 10,
    heartbeat: TimeSpan::fromSeconds(5),
));

async(static function () use ($handleDeliveries): void {
    /** @var Nats\JetStream\Delivery $delivery */
    foreach ($handleDeliveries as $delivery) {
        dump("Handle event with ack=explicit: {$delivery->message->payload} ({$delivery->subject})");
        $delivery->ack();
    }
});

for ($i = 0; $i < 10; ++$i) {
    $js->publish(
        subject: 'events.payment_rejected',
        message: new Nats\Message(
            payload: "Message#{$i}",
            headers: (new Nats\Headers())
                ->with(Nats\Header\MsgId::header(), "id:{$i}"),
        ),
    );
}

trapSignal([\SIGINT, \SIGTERM]);

$logDeliveries->complete();
$handleDeliveries->complete();

$client->disconnect();
```

#### Get message

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Api\StreamConfig;

$client = new Nats\Client(Nats\Config::default());
$js = $client->jetStream();

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

$client->disconnect();
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

$client = new Nats\Client(Nats\Config::default());
$js = $client->jetStream();

$kv = $js->createOrUpdateKeyValue(new BucketConfig(
    bucket: 'configs',
));

$kv->put('app.env', 'prod');
$kv->put('database.dsn', 'mysql:host=127.0.0.1;port=3306');

dump(
    $kv->get('app.env')?->value,
    $kv->get('database.dsn')?->value,
);

$client->disconnect();
```

#### Watch KV

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\KeyValue\BucketConfig;
use function Amp\trapSignal;

$client = new Nats\Client(Nats\Config::default());
$js = $client->jetStream();

$js->deleteKeyValue('configs');

$kv = $js->createOrUpdateKeyValue(new BucketConfig(
    bucket: 'configs',
));

$cancel = $kv
    ->watch()
    ->subscribe(static function (Nats\JetStream\KeyValue\Entry $entry): void {
        dump("Config key {$entry->key} value changed to {$entry->value}");
    });

$kv->put('app.env', 'prod');
$kv->put('database.dsn', 'mysql:host=127.0.0.1;port=3306');

trapSignal([\SIGTERM, \SIGINT]);

$cancel();

$client->disconnect();
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

$client = new Nats\Client(Nats\Config::default());
$js = $client->jetStream();

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

$client->disconnect();
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

$client = new Nats\Client(Nats\Config::default());
$js = $client->jetStream();

$js->deleteObjectStore('code');

$store = $js->createOrUpdateObjectStore(new StoreConfig(
    store: 'code',
));

$cancel = $store
    ->watch()
    ->subscribe(static function (ObjectInfo $info): void {
        dump("New object {$info->name} in the bucket {$info->bucket} at size {$info->size} bytes");
    });

$store->put(new ObjectMeta('config.php'), '<?php return [];');
$store->put(new ObjectMeta('snippet.php'), '<?php echo 1 + 1;');

delay(0.5);

$cancel();

$client->disconnect();
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

$client = new Nats\Client(Nats\Config::default());
$jetstream = $client->jetStream();

$counter = $jetstream->createOrUpdateCounter(new CounterConfig(
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

$client = new Nats\Client(Nats\Config::default());
$jetstream = $client->jetStream();

$counter = $jetstream->createOrUpdateCounter(new CounterConfig(
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

$client = new Nats\Client(Nats\Config::default());
$jetstream = $client->jetStream();

$jetstream->deleteCounter('atomics');

$counter = $jetstream->createOrUpdateCounter(new CounterConfig(
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

$client = new Nats\Client(Nats\Config::default());
$jetstream = $client->jetStream();

$stream = $jetstream->createStream(new StreamConfig(
    name: 'RecurrentsStream',
    subjects: [
        'recurrents',
        'scheduler.recurrents.*',
    ],
    allowMsgSchedules: true,
));

$jetstream->publish('scheduler.recurrents.1', new Nats\Message(
    payload: '{"id":1}',
    headers: (new Nats\Headers())
        ->with(Header\Schedule::Header, new \DateTimeImmutable('+5 seconds'))
        ->with(Header\ScheduleTarget::header(), 'recurrents'),
));

$consumer = $stream->createOrUpdateConsumer(new ConsumerConfig(
    durableName: 'RecurrentsConsumer',
    deliverPolicy: DeliverPolicy::New,
    ackPolicy: AckPolicy::None,
    filterSubjects: ['recurrents'],
));

foreach ($consumer->consume() as $delivery) {
    dump([
        $delivery->message->payload,
        $delivery->message->headers?->get(Header\Scheduler::header()),
        $delivery->message->headers?->get(Header\ScheduleNext::header()),
    ]);
}
```

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
