<?php

namespace ControleOnline\MessageHandler;

use ControleOnline\Message\SendTicketPushMessage;
use ControleOnline\Service\TicketPushService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class TicketPushMessageHandler
{
    public function __construct(private TicketPushService $ticketPushService) {}

    public function __invoke(SendTicketPushMessage $message): void
    {
        $this->ticketPushService->send($message->peopleIds, $message->title, $message->body, $message->taskId);
    }
}
