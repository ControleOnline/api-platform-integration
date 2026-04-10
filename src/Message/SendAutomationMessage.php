<?php

namespace ControleOnline\Message;

class SendAutomationMessage
{
    public function __construct(
        public array $messageData,
        public int $connectionId,
        public int $taskId
    ) {}
}
