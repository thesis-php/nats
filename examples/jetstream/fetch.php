<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Api;

$nc = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222?no_responders=true'));
$js = $nc->jetStream();

$stream = $js->createOrUpdateStream(new Api\StreamConfig(
    name: 'EventsStream',
    description: 'Testing Stream',
    subjects: ['events.*'],
));

$consumer = $stream->createOrUpdateConsumer(
    new Api\ConsumerConfig(durableName: 'EventPullConsumer', ackPolicy: Api\AckPolicy::Explicit),
);

$batch = $consumer
    ->pulling()
    ->fetch(Nats\JetStream\FetchConfig::batch(5));

foreach ($batch as $delivery) {
    dump($delivery->message->payload);
    $delivery->ack();
}

$nc->stop();
