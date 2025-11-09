<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\ObjectStore\ObjectMeta;
use Thesis\Nats\JetStream\ObjectStore\ResourceReader;

$nc = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222'));
$js = $nc->jetStream();
$js->deleteObjectStore('code');

$os = $js->createOrUpdateObjectStore(new Nats\JetStream\ObjectStore\StoreConfig('code', description: 'php code snippets'));
$handle = fopen(__DIR__ . '/store.php', 'r') ?: throw new RuntimeException('Unable to open file.');
$rdr = new ResourceReader($handle);

$info = $os->put(new ObjectMeta(name: 'store.php'), $rdr);

fclose($handle);

$object = $os->get('store.php');
dump((string) $object);
