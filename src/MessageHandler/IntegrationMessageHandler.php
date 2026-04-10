<?php

namespace ControleOnline\MessageHandler;

use ControleOnline\Entity\Integration;
use ControleOnline\Message\SendIntegrationMessage;
use ControleOnline\Service\IntegrationService;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Doctrine\ORM\EntityManagerInterface;

#[AsMessageHandler]
class IntegrationMessageHandler
{
    private $lock;
    public function __construct(
        private IntegrationService $integrationService,
        private LockFactory $lockFactory,
        private EntityManagerInterface $em
    ) {
        $this->lock = $this->lockFactory->createLock('integration:start');
    }

    public function __invoke(SendIntegrationMessage $message)
    {
        $integration = $this->em->getRepository(Integration::class)->find($message->integrationId);
        if (!$integration)
            return;

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
