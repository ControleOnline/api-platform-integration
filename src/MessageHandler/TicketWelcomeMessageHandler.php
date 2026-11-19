<?php

namespace ControleOnline\MessageHandler;

use ControleOnline\Entity\People;
use ControleOnline\Entity\Task;
use ControleOnline\Entity\TaskInteration;
use ControleOnline\Message\SendTicketWelcomeMessage;
use ControleOnline\Service\TicketAttendanceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sends, once, the welcome of the kind of request in the name of its AI person (a public interaction,
 * so TaskInterationService::notifyClient also delivers it on WhatsApp / e-mail).
 */
#[AsMessageHandler]
class TicketWelcomeMessageHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private TicketAttendanceService $attendance,
    ) {}

    public function __invoke(SendTicketWelcomeMessage $message): void
    {
        $task = $this->em->getRepository(Task::class)->find($message->taskId);
        $company = $task?->getProvider();
        if (!$task instanceof Task || !$company instanceof People) {
            return;
        }
        $text = $this->attendance->welcome($company, (string) $task->getType());
        if ($text === null) {
            return;
        }
        $author = $this->attendance->aiPeople($company, (string) $task->getType());
        if ($this->em->getRepository(TaskInteration::class)->count(['task' => $task, 'registeredBy' => $author]) > 0) {
            return;
        }
        $row = new TaskInteration();
        $row->setTask($task);
        $row->setRegisteredBy($author);
        $row->setType('comment');
        $row->setBody($text);
        $row->setVisibility('public');
        $row->setStatus('done');
        $row->setRead(0);
        $row->setNotified(0);
        $this->em->persist($row);
        $this->em->flush();
    }
}
