<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Api;
use Thesis\Time\TimeSpan;
use function Amp\async;
use function Amp\trapSignal;

$client = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222'));
$js = $client->jetStream();

foreach ($js->streamNames('push.*') as $streamName) {
    $js->deleteStream($streamName);
}

$stream = $js->createStream(new Api\StreamConfig(
    name: 'PushEventsStream',
    description: 'Testing Stream',
    subjects: ['push.*'],
));

$consumer = $stream->createOrUpdatePushConsumer(
    new Api\ConsumerConfig(
        durableName: 'PushEventsConsumer',
        deliverSubject: 'pushes',
        ackPolicy: Api\AckPolicy::Explicit,
        flowControl: true,
        idleHeartbeat: TimeSpan::fromSeconds(2),
        maxAckPending: 10,
    ),
);

$messages = $consumer->consume();

$future = async(static function () use ($messages): void {
    foreach ($messages as $message) {
        dump($message->message->payload);
        $message->ack();
    }
});

for ($i = 0; $i < 1_000; ++$i) {
    $js->publish("push.{$i}", new Nats\Message("{$i}"));
}

$signal = trapSignal([\SIGINT, \SIGTERM]);
$messages->complete();
$future->await();
dump("Terminate signal '{$signal}' received.");
