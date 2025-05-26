<?php

namespace ControleOnline\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface
as Security;
use ControleOnline\Entity\Connection;
use ControleOnline\Messages\MessageInterface;

class AutomationMessagesService
{
    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,

    ) {}
    public function receiveMessage(MessageInterface $message, Connection $connection)
    {
        $content = $message->getMessageContent();
        $connection->gettype();
    }
}
