<?php

declare(strict_types=1);

use Thesis\Nats;
use Thesis\Nats\Micro;

require __DIR__ . '/../../vendor/autoload.php';

$nc = new Nats\Client(
    Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222'),
);

$svc = $nc->createService(
    new Micro\Config('EchoService', '1.0.0'),
);

$svc->addEndpoint('scv.echo', function (Micro\Request $request): void {
    $request->respondJson($request->data);
});

dump($nc->request('scv.echo', new Nats\Message('Hello!')));
dump($nc->request('scv.echo', new Nats\Message('Hello!')));
