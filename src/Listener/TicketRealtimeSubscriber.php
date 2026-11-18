<?php

namespace ControleOnline\Listener;

use ControleOnline\Entity\People;
use ControleOnline\Entity\PeopleLink;
use ControleOnline\Entity\Task;
use ControleOnline\Entity\TaskInteration;
use ControleOnline\Event\EntityChangedEvent;
use ControleOnline\Service\ConversationRealtimeNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Requests are live (app-community#1597/#1599): a new message, or a change of status / responsible /
 * context, is announced over the websocket right after the HTTP response (kernel.terminate). The websocket
 * server polls open "Websocket" integrations every second for connected devices, so no worker/cron is
 * involved (never execute those integrations here: that would close them before the server sends them). Both sides are told: the provider company (analysts, CRM) and the client's
 * companies (the customer in /support). Collected during the flush, sent after it (never inside it).
 */
class TicketRealtimeSubscriber implements EventSubscriberInterface
{
    private const TYPES = ['relationship', 'support', 'ia'];

    /** @var array<int, array{task: Task, interaction: ?int}> */
    private array $pending = [];

    public function __construct(
        private ConversationRealtimeNotifier $realtime,
        private EntityManagerInterface $manager,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            EntityChangedEvent::class => 'onEntityChanged',
            KernelEvents::TERMINATE => ['flush', -10],
        ];
    }

    public function onEntityChanged(EntityChangedEvent $event): void
    {
        $entity = $event->getEntity();
        $phase = $event->getPhase();
        if ($entity instanceof TaskInteration && in_array($phase, ['postPersist', EntityChangedEvent::POST_PERSIST], true)) {
            $task = $entity->getTask();
            if ($task instanceof Task && in_array($task->getType(), self::TYPES, true) && $task->getId()) {
                $this->pending[$task->getId()] = ['task' => $task, 'interaction' => $entity->getId()];
            }
        } elseif ($entity instanceof Task && in_array($phase, ['postUpdate', EntityChangedEvent::POST_UPDATE], true)) {
            if (in_array($entity->getType(), self::TYPES, true) && $entity->getId()) {
                $this->pending[$entity->getId()] ??= ['task' => $entity, 'interaction' => null];
            }
        }
    }

    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as $item) {
            try {
                $this->announce($item['task'], $item['interaction']);
            } catch (\Throwable) {
                // Realtime is best effort: the screens also reload on their own.
            }
        }
    }

    private function announce(Task $task, ?int $interactionId): void
    {
        $event = ['event' => 'conversation_message', 'status' => 'received', 'interaction_id' => $interactionId];
        $companies = [];
        if ($task->getProvider() instanceof People) {
            $companies[$task->getProvider()->getId()] = $task->getProvider();
        }
        foreach ($this->clientCompanies($task) as $company) {
            $companies[$company->getId()] = $company;
        }
        foreach ($companies as $company) {
            $this->realtime->notify($company, $task, $event);
        }
    }

    /** @return list<People> the client itself (a company) or the companies the client person works for */
    private function clientCompanies(Task $task): array
    {
        $client = $task->getClient();
        if (!$client instanceof People) {
            return [];
        }
        $out = [$client];
        foreach ($this->manager->getRepository(PeopleLink::class)->findBy(['people' => $client, 'linkType' => PeopleLink::EMPLOYEE_LINK]) as $link) {
            if ($link->getCompany() instanceof People) {
                $out[] = $link->getCompany();
            }
        }

        return array_slice($out, 0, 5);
    }
}
