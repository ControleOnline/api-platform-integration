<?php

namespace ControleOnline\Listener;

use ControleOnline\Entity\People;
use ControleOnline\Entity\Task;
use ControleOnline\Entity\TaskInteration;
use ControleOnline\Event\EntityChangedEvent;
use ControleOnline\Message\SendTicketPushMessage;
use ControleOnline\Service\TicketRulesService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Request pushes (app-community#1598), relationship and support:
 * - new message: the responsible person and the customer (requester), never the author;
 *   private notes only reach the responsible person;
 * - request created with a responsible person, or responsible changed: the responsible person.
 */
class TicketPushSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MessageBusInterface $bus,
        private TicketRulesService $ticketRules,
        private TokenStorageInterface $tokenStorage,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [EntityChangedEvent::class => 'onEntityChanged'];
    }

    public function onEntityChanged(EntityChangedEvent $event): void
    {
        try {
            $entity = $event->getEntity();
            $phase = $event->getPhase();
            if ($entity instanceof TaskInteration && in_array($phase, ['postPersist', EntityChangedEvent::POST_PERSIST], true)) {
                $this->onMessage($entity);
            } elseif ($entity instanceof Task && in_array($phase, ['postPersist', EntityChangedEvent::POST_PERSIST], true)) {
                $this->onResponsible($entity, $entity->getRegisteredBy(), 'Novo chamado para você');
            } elseif ($entity instanceof Task && in_array($phase, ['postUpdate', EntityChangedEvent::POST_UPDATE], true)) {
                $old = $event->getOldEntity();
                if ($old instanceof Task && $old->getTaskFor()?->getId() !== $entity->getTaskFor()?->getId()) {
                    $this->onResponsible($entity, $this->me(), 'Chamado atribuído a você');
                }
            }
        } catch (\Throwable) {
            // Push is best effort: never break the request that changed the ticket.
        }
    }

    private function onMessage(TaskInteration $interaction): void
    {
        $task = $interaction->getTask();
        if (!TicketRulesService::isTicket($task) || $interaction->getType() !== 'comment') {
            return;
        }
        $author = $interaction->getRegisteredBy();
        $recipients = [$task->getTaskFor()];
        if ($interaction->getVisibility() !== 'private') {
            $recipients[] = $this->requester($task);
        }
        $from = $author instanceof People ? ($author->getAlias() ?: $author->getName()) : '';
        $body = trim((string) $interaction->getBody());
        $body = $body !== '' ? mb_substr($body, 0, 140) : ($interaction->getFile() ? '📎 Anexo' : 'Nova mensagem');

        $this->dispatch($task, $recipients, $author, trim($from . ' · ' . $this->label($task), ' ·'), $body);
    }

    private function onResponsible(Task $task, ?People $author, string $title): void
    {
        if (!TicketRulesService::isTicket($task) || !$task->getTaskFor()) {
            return;
        }
        $this->dispatch($task, [$task->getTaskFor()], $author, $title, $this->label($task));
    }

    /** The customer: whoever opened the request from the customer side, else the client itself. */
    private function requester(Task $task): ?People
    {
        $opener = $task->getRegisteredBy();
        if ($opener instanceof People && $this->ticketRules->isCustomerSide($task, $opener)) {
            return $opener;
        }

        return $task->getClient();
    }

    /** @param list<People|null> $recipients */
    private function dispatch(Task $task, array $recipients, ?People $author, string $title, string $body): void
    {
        $ids = [];
        foreach ($recipients as $people) {
            if ($people instanceof People && $people->getId() !== $author?->getId()) {
                $ids[$people->getId()] = (int) $people->getId();
            }
        }
        if ($ids !== [] && $task->getId()) {
            $this->bus->dispatch(new SendTicketPushMessage(array_values($ids), $title, $body, (int) $task->getId()));
        }
    }

    private function label(Task $task): string
    {
        return '#' . $task->getId() . ' ' . mb_substr((string) $task->getName(), 0, 60);
    }

    private function me(): ?People
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return is_object($user) && method_exists($user, 'getPeople') ? $user->getPeople() : null;
    }
}
