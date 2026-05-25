<?php

namespace ControleOnline\Message;

class SendManagerEventPushMessage
{
    public function __construct(
        public int $companyId,
        public array $event
    ) {}
}
