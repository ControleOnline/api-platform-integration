<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Task;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface
as Security;
use ControleOnline\Entity\Connection;
use ControleOnline\Messages\MessageInterface;

class AutomationMessagesService
{
    public function __construct(
        private N8NService $n8nService,
    ) {}

    public function receiveMessage(MessageInterface $message, Connection $connection, Task $task)
    {
        return $this->n8nService->sendToWebhook($message, $connection, $task);
    }
}
