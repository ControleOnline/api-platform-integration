<?php

namespace ControleOnline\Event;

use ControleOnline\Entity\Order;
use Symfony\Contracts\EventDispatcher\Event;

class OrderUpdatedEvent extends Event
{
    public $order;
    public function setOrder(Order $order)
    {
        $this->order = $order;
        return $this;
    }
}
