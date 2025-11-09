<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Api\StreamConfig;

$nc = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222?no_responders=true'));
$js = $nc->jetStream();

$js->deleteStreams('batches');

$stream = $js->createStream(new StreamConfig(
    name: 'batches',
    description: 'Batch Stream',
    subjects: ['batch.*'],
    allowAtomicPublish: true,
));

$js->publishBatch('batch.orders', [
    new Nats\Message('Order#1'),
    new Nats\Message('Order#2'),
    new Nats\Message('Order#3'),
]);
