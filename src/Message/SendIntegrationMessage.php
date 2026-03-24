<?php

namespace ControleOnline\Message;


class SendIntegrationMessage
{
    public function __construct(
        public int $integrationId
    ) {}
}
