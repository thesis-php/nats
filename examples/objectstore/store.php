<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\ObjectStore\ObjectMeta;

$client = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222'));
$jetstream = $client->jetStream();
$jetstream->deleteObjectStore('images');

$store = $jetstream->createOrUpdateObjectStore(new Nats\JetStream\ObjectStore\StoreConfig('images'));

$info = $store->put(
    new ObjectMeta(name: 'image1'),
    str_repeat('x', 20) . str_repeat('y', 10) . str_repeat('z', 50),
);

$object = $store->get('image1');
dump((string) $object);
