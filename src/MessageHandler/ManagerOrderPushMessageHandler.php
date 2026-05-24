<?php

namespace ControleOnline\MessageHandler;

use ControleOnline\Entity\Order;
use ControleOnline\Message\SendManagerOrderPushMessage;
use ControleOnline\Service\ManagerOrderPushService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ManagerOrderPushMessageHandler
{
    public function __construct(
        private EntityManagerInterface $manager,
        private ManagerRegistry $managerRegistry,
        private ManagerOrderPushService $managerOrderPushService
    ) {}

    public function __invoke(SendManagerOrderPushMessage $message): void
    {
        $manager = $this->getManager();
        $order = $manager->getRepository(Order::class)->find($message->orderId);
        if (!$order instanceof Order) {
            return;
        }

        $this->managerOrderPushService->sendOrderCreatedNotification($order);
    }

    private function getManager(): EntityManagerInterface
    {
        if (method_exists($this->manager, 'isOpen') && $this->manager->isOpen()) {
            return $this->manager;
        }

        $this->managerRegistry->resetManager();
        $manager = $this->managerRegistry->getManagerForClass(Order::class);

        if (!$manager instanceof EntityManagerInterface) {
            throw new \RuntimeException('Doctrine entity manager unavailable for ManagerOrderPushMessageHandler.');
        }

        $this->manager = $manager;

        return $this->manager;
    }
}
