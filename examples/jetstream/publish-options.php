<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Amp\TimeoutCancellation;
use Thesis\Nats;
use Thesis\Nats\JetStream\Api;
use Thesis\Time\TimeSpan;

$nc = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222?no_responders=true'));
$js = $nc->jetStream();

$js->deleteStreams('OrdersStream');

$js->createStream(new Api\StreamConfig(
    name: 'OrdersStream',
    description: 'Testing Stream',
    subjects: ['orders.*'],
    duplicateWindow: TimeSpan::fromMinutes(2),
    allowMessageTtl: true,
));

// Deduplication: the second publish with the same id is not stored.
$ack = $js->publish('orders.created', new Nats\Message('Order#1'), new Nats\PublishOptions(msgId: 'order-1'));
dump($ack->seq);

$ack = $js->publish('orders.created', new Nats\Message('Order#1'), new Nats\PublishOptions(msgId: 'order-1'));
dump($ack->seq, $ack->duplicate);

// Optimistic concurrency control on the whole stream.
$ack = $js->publish('orders.paid', new Nats\Message('Order#1'), new Nats\PublishOptions(
    expectedStream: 'OrdersStream',
    expectedLastSequence: 1,
    expectedLastMsgId: 'order-1',
));
dump($ack->seq);

try {
    $js->publish('orders.paid', new Nats\Message('Order#2'), new Nats\PublishOptions(expectedLastSequence: 1));
} catch (Nats\Exception\WrongLastSequence $e) {
    dump($e->getMessage());
}

// Optimistic concurrency control on a set of subjects and a message that expires in an hour.
$ack = $js->publish('orders.cancelled', new Nats\Message('Order#1'), new Nats\PublishOptions(
    expectedLastSubjectSequence: 2,
    expectedLastSubjectSequenceSubject: 'orders.*',
    ttl: TimeSpan::fromHours(1),
));
dump($ack->seq);

// Retry while the stream is unavailable, but give up after 5 seconds.
$ack = $js->publish(
    'orders.created',
    new Nats\Message('Order#2'),
    new Nats\PublishOptions(
        retryAttempts: 10,
        retryWait: TimeSpan::fromMilliseconds(500),
    ),
    new TimeoutCancellation(5),
);
dump($ack->seq);

$js->deleteStream('OrdersStream');

$nc->stop();
