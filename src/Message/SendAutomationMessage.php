<?php

namespace ControleOnline\Message;

use ControleOnline\Entity\Connection;
use ControleOnline\Entity\Task;
use ControleOnline\Messages\MessageInterface;

class SendAutomationMessage
{
    public function __construct(
        public array $messageData,
        public int $connectionId,
        public int $taskId
    ) {}
}
