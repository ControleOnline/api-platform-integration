<?php

namespace ControleOnline\MessageHandler;

use ControleOnline\Entity\Integration;
use ControleOnline\Service\IntegrationService;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class IntegrationMessageHandler
{
    private $lock;
    public function __construct(
        private IntegrationService $integrationService,
        private LockFactory $lockFactory,
    ) {
        $this->lock = $this->lockFactory->createLock('integration:start');
    }

    public function __invoke(Integration $integration)
    {
        if (!$integration)
            return;

        if ($this->lock->acquire()) {
            $this->integrationService->executeIntegration($integration);
            $this->lock->release();
        }
    }
}
