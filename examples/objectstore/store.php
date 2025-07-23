<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\ObjectStore\ObjectMeta;

$client = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222'));
$jetstream = $client->jetStream();
$jetstream->deleteObjectStore('images');

$object = $jetstream->createOrUpdateObjectStore(new Nats\JetStream\ObjectStore\StoreConfig('images'));

$info = $object->put(
    new ObjectMeta(name: 'image1'),
    str_repeat('x', 10),
);

dump($info);
