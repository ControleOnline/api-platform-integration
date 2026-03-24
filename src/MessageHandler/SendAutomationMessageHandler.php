<?php

namespace ControleOnline\MessageHandler;

use ControleOnline\Entity\Connection;
use ControleOnline\Entity\Task;
use ControleOnline\Message\SendAutomationMessage;
use ControleOnline\Service\N8NService;
use ControleOnline\WhatsApp\Messages\WhatsAppMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendAutomationMessageHandler
{
    public function __construct(
        private N8NService $n8nService,
        private EntityManagerInterface $em
    ) {}

    public function __invoke(SendAutomationMessage $message)
    {
        $connection = $this->em->getRepository(Connection::class)->find($message->connectionId);
        $task = $this->em->getRepository(Task::class)->find($message->taskId);

        $whatsAppMessage = new WhatsAppMessage();
        $whatsAppMessage->setAction($message['action']);
        $whatsAppMessage->setOriginNumber($message['origin']);
        $whatsAppMessage->setDestinationNumber($message['destination']);
        $whatsAppMessage->setMessageContent($message['message']);

        $this->n8nService->sendToWebhook(
            $whatsAppMessage,
            $connection,
            $task
        );
    }
}
