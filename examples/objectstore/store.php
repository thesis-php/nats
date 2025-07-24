<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\ObjectStore\ObjectMeta;
use Thesis\Nats\JetStream\ObjectStore\ResourceReader;

$client = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222'));
$jetstream = $client->jetStream();
$jetstream->deleteObjectStore('code');

$store = $jetstream->createOrUpdateObjectStore(new Nats\JetStream\ObjectStore\StoreConfig('code', description: 'php code snippets'));
$handle = fopen(__DIR__ . '/store.php', 'r') ?: throw new RuntimeException('Unable to open file.');
$rdr = new ResourceReader($handle);

$info = $store->put(new ObjectMeta(name: 'store.php'), $rdr);

fclose($handle);

$object = $store->get('store.php');
dump((string) $object);
