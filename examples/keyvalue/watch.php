<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Thesis\Nats;
use Thesis\Nats\JetStream\KeyValue;
use function Amp\async;
use function Amp\delay;

$client = new Nats\Client(Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222'));
$js = $client->jetStream();

try {
    $js->deleteKeyValue('profiles');
} catch (Nats\Exception\StreamNotFound) {
}

$kv = $js->createOrUpdateKeyValue(new KeyValue\BucketConfig('profiles'));

$watcher = $kv->watch(config: new KeyValue\WatchConfig(ignoreDeletes: true));

$future = async(static function () use ($watcher): void {
    foreach ($watcher as $entry) {
        dump($entry->value);
    }
});

for ($i = 0; $i < 10; ++$i) {
    $kv->put($key = "user.{$i}", "id:{$i}");

    if ($i % 2 === 0) {
        $kv->delete($key);
    }
}

delay(0.5);

$watcher->complete();
$future->await();
