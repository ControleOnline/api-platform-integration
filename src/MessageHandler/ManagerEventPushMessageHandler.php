<?php

namespace ControleOnline\MessageHandler;

use ControleOnline\Entity\People;
use ControleOnline\Message\SendManagerEventPushMessage;
use ControleOnline\Service\ManagerOrderPushService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ManagerEventPushMessageHandler
{
    public function __construct(
        private EntityManagerInterface $manager,
        private ManagerRegistry $managerRegistry,
        private ManagerOrderPushService $managerOrderPushService
    ) {}

    public function __invoke(SendManagerEventPushMessage $message): void
    {
        $manager = $this->getManager();
        $company = $manager->getRepository(People::class)->find($message->companyId);
        if (!$company instanceof People) {
            return;
        }

        $this->managerOrderPushService->sendCompanyEventNotification(
            $company,
            $message->event
        );
    }

    private function getManager(): EntityManagerInterface
    {
        if (method_exists($this->manager, 'isOpen') && $this->manager->isOpen()) {
            return $this->manager;
        }

        $this->managerRegistry->resetManager();
        $manager = $this->managerRegistry->getManagerForClass(People::class);

        if (!$manager instanceof EntityManagerInterface) {
            throw new \RuntimeException('Doctrine entity manager unavailable for ManagerEventPushMessageHandler.');
        }

        $this->manager = $manager;

        return $this->manager;
    }
}
