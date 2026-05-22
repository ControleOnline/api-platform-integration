<?php

namespace ControleOnline\MessageHandler;

use ControleOnline\Entity\Integration;
use ControleOnline\Message\SendIntegrationMessage;
use ControleOnline\Service\IntegrationService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class IntegrationMessageHandler
{
    private $lock;
    public function __construct(
        private IntegrationService $integrationService,
        private LockFactory $lockFactory,
        private EntityManagerInterface $em,
        private ManagerRegistry $managerRegistry
    ) {
        $this->lock = $this->lockFactory->createLock('integration:start');
    }

    private function getManager(): EntityManagerInterface
    {
        if (method_exists($this->em, 'isOpen') && $this->em->isOpen()) {
            return $this->em;
        }

        $this->managerRegistry->resetManager();
        $manager = $this->managerRegistry->getManagerForClass(Integration::class);

        if (!$manager instanceof EntityManagerInterface) {
            throw new \RuntimeException('Doctrine entity manager unavailable for IntegrationMessageHandler.');
        }

        $this->em = $manager;

        return $this->em;
    }

    public function __invoke(SendIntegrationMessage $message)
    {
        $manager = $this->getManager();
        $integration = $manager->getRepository(Integration::class)->find($message->integrationId);
        if (!$integration)
            return;

        if (strcasecmp((string) $integration->getQueueName(), 'Websocket') === 0) {
            return;
        }

        // Bloqueia ate obter o lock para evitar consumir e descartar mensagens
        // quando outro webhook estiver em processamento.
        if (!$this->lock->acquire(true)) {
            return;
        }

        try {
            $this->integrationService->executeIntegration($integration);
        } finally {
            if ($this->lock->isAcquired()) {
                $this->lock->release();
            }
        }
    }
}
