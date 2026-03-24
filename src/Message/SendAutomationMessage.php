<?php

namespace ControleOnline\Message;

use ControleOnline\Entity\Connection;
use ControleOnline\Entity\Task;
use ControleOnline\Messages\MessageInterface;

class SendAutomationMessage
{
    public function __construct(
        public MessageInterface $message,
        public Connection $connection,
        public Task $task
    ) {}
}
