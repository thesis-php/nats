<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\ObjectStore;

$client = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222'));
$js = $client->jetStream();
$js->deleteObjectStore('code');

$os = $js->createOrUpdateObjectStore(new ObjectStore\StoreConfig('code', description: 'php code snippets'));
$handle = fopen(__DIR__ . '/store.php', 'r') ?: throw new RuntimeException('Unable to open file.');
$rdr = new ObjectStore\ResourceReader($handle);

$info = $os->put(new ObjectStore\ObjectMeta(name: 'store.php'), $rdr);

fclose($handle);

$object = $os->get('store.php');
dump((string) $object);
