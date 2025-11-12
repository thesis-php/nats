<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Api;
use Thesis\Time\TimeSpan;
use function Amp\async;
use function Amp\trapSignal;

$nc = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222?no_responders=true'));
$js = $nc->jetStream();

$stream = $js->createOrUpdateStream(new Api\StreamConfig(
    name: 'EventsStream',
    description: 'Testing Stream',
    subjects: ['events.*'],
));

try {
    $stream->deleteConsumer('EventPushConsumer');
} catch (Nats\Exception\ConsumerNotFound) {
}

$consumer = $stream->createOrUpdateConsumer(new Api\ConsumerConfig(
    durableName: 'EventPushConsumer',
    deliverSubject: 'push-consumer-delivery',
    ackPolicy: Api\AckPolicy::Explicit,
    idleHeartbeat: TimeSpan::fromSeconds(1),
    maxAckPending: 1,
));

$subscription = $consumer->push(
    static function (Nats\JetStream\Delivery $delivery): void {
        dump($delivery->message->payload);
        $delivery->ack();
    },
);

async(static function () use ($subscription): void {
    trapSignal([\SIGINT, \SIGTERM]);
    $subscription->stop();
});

$subscription->awaitCompletion();

$nc->stop();
