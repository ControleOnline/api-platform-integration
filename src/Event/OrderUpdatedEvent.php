<?php

namespace ControleOnline\Event;

use ControleOnline\Entity\Order;
use Symfony\Contracts\EventDispatcher\Event;

class OrderUpdatedEvent extends Event
{
    public function __construct(public readonly Order $order) {}
}
