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

$nc->disconnect();
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

$nc->disconnect();
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

$nc->disconnect();
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
use function Amp\trapSignal;

$nc = new Nats\Client(Nats\Config::default());
$js = $nc->jetStream();

$js->deleteStream('EventStream');

$stream = $js->createStream(new StreamConfig(
    name: 'EventStream',
    description: 'Application events',
    subjects: ['events.*'],
));

$logConsumer = $stream
    ->createConsumer(new ConsumerConfig(
        durableName: 'EventLog',
        ackPolicy: AckPolicy::None,
    ))
    ->asPull()
    ;

$logSubscription = $logConsumer->consume(
    static function (Nats\JetStream\Delivery $delivery): void {
        dump("Log event with ack=none: {$delivery->message->payload} ({$delivery->subject})");
    },
    new ConsumeConfig(
        batch: 10,
        heartbeat: TimeSpan::fromSeconds(5),
    ),
);

$handleConsumer = $stream
    ->createConsumer(new ConsumerConfig(
        durableName: 'EventHandle',
        ackPolicy: AckPolicy::Explicit,
    ))
    ->asPull()
    ;

$handleSubscription = $handleConsumer->consume(
    static function (Nats\JetStream\Delivery $delivery): void {
        dump("Handle event with ack=explicit: {$delivery->message->payload} ({$delivery->subject})");
        $delivery->ack();
    },
    new ConsumeConfig(
        batch: 10,
        heartbeat: TimeSpan::fromSeconds(5),
    ),
);

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

$logSubscription->drain();
$handleSubscription->drain();

$nc->disconnect();
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

$nc->disconnect();
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

$nc->disconnect();
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

$nc->disconnect();
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

$nc->disconnect();
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

$nc->disconnect();
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

$consumer = $stream
    ->createOrUpdateConsumer(new ConsumerConfig(
        durableName: 'RecurrentsConsumer',
        deliverPolicy: DeliverPolicy::New,
        ackPolicy: AckPolicy::None,
        filterSubjects: ['recurrents'],
    ))
    ->asPull()
    ;

$consumer->consume(static function (Nats\JetStream\Delivery $delivery): void {
    dump([
        $delivery->message->payload,
        $delivery->message->headers?->get(Header\Scheduler::header()),
        $delivery->message->headers?->get(Header\ScheduleNext::header()),
    ]);
});
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

After a service is created, endpoints can be added. By default, an endpoint is available via its name.

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
    ->addEndpoint(new Micro\EndpointConfig('srv.echo'), static function (Micro\Request $request): void {
        $request->respond(new Micro\Response($request->data));
    });

dump($nc->request('srv.echo', new Nats\Message('ping'))->message->payload);
```

If the subject for the endpoint is more complex (e.g., contains a `*` or `>`), the subject can be specified separately from the name.

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
    ->addEndpoint(new Micro\EndpointConfig('srv.echo', subject: 'srv.echo.*'), static function (Micro\Request $request): void {
        $request->respond(new Micro\Response($request->subject));
    });

dump($nc->request('srv.echo.x', new Nats\Message('ping'))->message->payload);
```

#### Groups

Endpoints can also be aggregated using groups. A group represents a common subject prefix used by all endpoints associated with it.

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
