<?php

namespace ControleOnline\Command;

use ControleOnline\Entity\Integration;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;
use ControleOnline\Service\DatabaseSwitchService;
use ControleOnline\Service\DomainService;
use ControleOnline\Service\LoggerService;
use ControleOnline\Service\SkyNetService;
use Exception;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

class IntegrationCommand extends DefaultCommand
{
    protected $input;
    protected $output;
    protected $lock;

    public function __construct(
        LockFactory $lockFactory,
        DatabaseSwitchService $databaseSwitchService,
        LoggerService $loggerService,
        SkyNetService $skyNetService,
        private IntegrationService $integrationService,
        private EntityManagerInterface $entityManager,
        private StatusService $statusService,
        private DomainService $domainService,
        private ContainerInterface $container,
    ) {
        $this->skyNetService = $skyNetService;
        $this->lockFactory = $lockFactory;
        $this->databaseSwitchService = $databaseSwitchService;
        $this->loggerService = $loggerService;
        parent::__construct('integration:start');
    }


    protected function configure(): void
    {
        $this->setDescription('Query the integration table and process records with pending status');
    }

    protected function runCommand(): int
    {
        if ($this->lock->acquire()) {
            $this->addLog('Iniciando a verificação da fila de integração...');
            $integrations = $this->integrationService->getAllOpenIntegrations(1000);

            foreach ($integrations as $integration)
                try {
                    $serviceName = 'ControleOnline\\Service\\' . $integration->getQueueName() . 'Service';
                    $this->addLog(sprintf('Iniciando o processamento do ID: %d - %s', $integration->getId(), $integration->getQueueName()));
                    $this->addLog('Service: ' . $serviceName);
                    $this->integrationService->executeIntegration($integration);
                } catch (Throwable $e) {
                    $statusError = $this->statusService->discoveryStatus('pending', 'error', 'integration');
                    $this->addLog(sprintf('<error>Erro ao processar o ID: %d. Erro: %s</error>', $integration->getId(), $e->getMessage()));
                    $this->addLog($e->getLine());
                    $this->addLog($e->getFile());
                    $integration->setStatus($statusError);
                    $this->entityManager->persist($integration);
                    $this->entityManager->flush();
                }


            $this->addLog('Verificação da fila de integração concluída.');

            return Command::SUCCESS;
        } else {
            $this->addLog('Outro processo ainda está em execução. Ignorando...');
            return Command::SUCCESS;
        }
    }
}
