<?php

namespace ControleOnline\Listener;

use ControleOnline\Entity\TaskInteration;
use ControleOnline\Event\EntityChangedEvent;
use ControleOnline\Message\SendTicketWelcomeMessage;
use ControleOnline\Service\TicketRulesService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Human-first requests: the first message of the request triggers the welcome (async). AI-first
 * requests get it from McpChat, right before the AI's first answer.
 */
class TicketWelcomeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MessageBusInterface $bus,
        private EntityManagerInterface $em,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [EntityChangedEvent::class => 'onEntityChanged'];
    }

    public function onEntityChanged(EntityChangedEvent $event): void
    {
        try {
            $interaction = $event->getEntity();
            if (!$interaction instanceof TaskInteration || !in_array($event->getPhase(), ['postPersist', EntityChangedEvent::POST_PERSIST], true)) {
                return;
            }
            $task = $interaction->getTask();
            if (!TicketRulesService::isTicket($task) || $task->getAiAgent() || $interaction->getVisibility() !== 'public'
                || $this->em->getRepository(TaskInteration::class)->count(['task' => $task]) > 1) {
                return;
            }
            $this->bus->dispatch(new SendTicketWelcomeMessage((int) $task->getId()));
        } catch (\Throwable) {
            // Best effort: never break the message that started the request.
        }
    }
}
