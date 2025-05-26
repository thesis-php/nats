<?php

declare(strict_types=1);

namespace Thesis\Nats;

/**
 * @api
 */
enum Status: int
{
    case Control = 100;
    case OK = 200;
    case BadRequest = 400;
    case NoMessages = 404;
    case ReqTimeout = 408;
    case MaxBytesExceeded = 409;
    case NoResponders = 503;
    case PinIdMismatch = 423;
    case Unknown = -1;
}
