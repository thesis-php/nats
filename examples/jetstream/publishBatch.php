<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\Api\StreamConfig;

$client = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222?no_responders=true'));
$jetstream = $client->jetStream();

try {
    $jetstream->deleteStream('Batches');
} catch (Nats\Exception\StreamNotFound) {
}


$stream = $jetstream->createStream(new StreamConfig(
    name: 'Batches',
    description: 'Batch Stream',
    subjects: ['batch.*'],
    allowAtomicPublish: true,
));

$jetstream->publishBatch('batch.orders', [
    new Nats\Message('Order#1'),
    new Nats\Message('Order#2'),
    new Nats\Message('Order#3'),
]);
