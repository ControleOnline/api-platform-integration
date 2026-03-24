<?php

namespace ControleOnline\MessageHandler;

use ControleOnline\Message\SendAutomationMessage;
use ControleOnline\Service\N8NService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendAutomationMessageHandler
{
    public function __construct(
        private N8NService $n8nService
    ) {}

    public function __invoke(SendAutomationMessage $message)
    {
        $this->n8nService->sendToWebhook(
            $message->message,
            $message->connection,
            $message->task
        );
    }
}
