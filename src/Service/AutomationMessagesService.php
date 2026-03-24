<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Task;
use ControleOnline\Entity\Connection;
use ControleOnline\Message\SendAutomationMessage;
use ControleOnline\Messages\MessageInterface;
use Symfony\Component\Messenger\MessageBusInterface;
class AutomationMessagesService
{
    public function __construct(
        private MessageBusInterface $bus

    ) {}

    public function receiveMessage(MessageInterface $message, Connection $connection, Task $task)
    {
        $this->bus->dispatch(new SendAutomationMessage(
            $message,
            $connection,
            $task
        ));
    }
}