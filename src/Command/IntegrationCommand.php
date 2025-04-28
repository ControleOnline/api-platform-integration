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
        private IntegrationService $integrationService,
        private EntityManagerInterface $entityManager,
        private StatusService $statusService,
        private DomainService $domainService,
        private ContainerInterface $container,
    ) {
        $this->lockFactory = $lockFactory;
        $this->databaseSwitchService = $databaseSwitchService;
        parent::__construct('integration:start');
    }


    protected function configure(): void
    {
        $this
            ->setDescription('Query the integration table and process records with pending status');
    }

    protected function executeIntegration(Integration $integration)
    {
        $serviceName = 'ControleOnline\\Service\\' . $integration->getQueueName() . 'Service';
        $method = 'integrate';
        $return = null;
        if ($this->container->has($serviceName)) {
            $service = $this->container->get($serviceName);
            if (method_exists($service, $method))
                $return = $service->$method($integration);
        }

        if ($return) $integration->setStatus($this->statusService->discoveryStatus('closed', 'closed', 'integration'));
        else $integration->setStatus($this->statusService->discoveryStatus('closed', 'not implemented', 'integration'));

        $this->entityManager->persist($integration);
        $this->entityManager->flush();
    }

    protected function runCommand(): int
    {
        if ($this->lock->acquire()) {
            $this->output->writeln('Iniciando a verificação da fila de integração...');
            $integrations = $this->integrationService->getAllOpenIntegrations(1000);

            foreach ($integrations as $integration)
                try {
                    $this->output->writeln(sprintf('Iniciando o processamento do ID: %d - %s', $integration->getId(), $integration->getQueueName()));
                    $this->executeIntegration($integration);
                } catch (Throwable $e) {
                    $statusError = $this->statusService->discoveryStatus('pending', 'error', 'integration');
                    $this->output->writeln(sprintf('<error>Erro ao processar o ID: %d. Erro: %s</error>', $integration->getId(), $e->getMessage()));
                    $integration->setStatus($statusError);
                    $this->entityManager->persist($integration);
                    $this->entityManager->flush();
                }


            $this->output->writeln('Verificação da fila de integração concluída.');

            return Command::SUCCESS;
        } else {
            $this->output->writeln('Outro processo ainda está em execução. Ignorando...');
            return Command::SUCCESS;
        }
    }
}
