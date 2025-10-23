<?php

declare(strict_types=1);

use Thesis\Nats;
use Thesis\Nats\Micro;

require __DIR__ . '/../../vendor/autoload.php';

$nc = new Nats\Client(
    Nats\Config::fromURI('tcp://user:Pswd1@nats-1:4222'),
);

$nc
    ->createService(new Micro\ServiceConfig('EchoService', '1.0.0'))
        ->addGroup(new Micro\GroupConfig('srv.api'))
            ->addGroup(new Micro\GroupConfig('v1'))
                ->addEndpoint(new Micro\EndpointConfig('echo'), static function (Micro\Request $request): void {
                    $request->respond(new Micro\Response($request->data));
                });

dump($nc->request('srv.api.v1.echo', new Nats\Message('ping'))->message->payload);
