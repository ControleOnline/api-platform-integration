<?php

namespace ControleOnline\Message;

class SendManagerOrderPushMessage
{
    public function __construct(
        public int $orderId
    ) {}
}
