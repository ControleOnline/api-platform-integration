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
use Symfony\Component\DependencyInjection\ContainerInterface;

class IntegrationCommand extends Command
{
    protected static $defaultName = 'app:process-integration-queue';

    public function __construct(
        private IntegrationService $integrationService,
        private EntityManagerInterface $entityManager,
        private StatusService $statusService,
        private LockFactory $lockFactory,
        private DatabaseSwitchService $databaseSwitchService,
        private DomainService $domainService,
        private ContainerInterface $container

    ) {
        $databaseSwitchService->switchDatabaseByDomain('api.controleonline.com');
        parent::__construct();
    }


    protected function configure()
    {
        $this
            ->setName('integration:start')
            ->setDescription('Consulta a tabela de integração e processa registros com status pendente.');
    }
    protected  function executeIntegration(Integration $integration)
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
        else $integration->getStatus($this->statusService->discoveryStatus('closed', 'not implemented', 'integration'));

        $this->entityManager->persist($integration);
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = $this->lockFactory->createLock('integration:start');

        if ($lock->acquire()) {
            $output->writeln('Iniciando a verificação da fila de integração...');

            $integrations = $this->integrationService->getOpen(['iFood', 'Asaas'], [], 1000);

            foreach ($integrations as $integration) {
                try {
                    $output->writeln(sprintf('Iniciando o processamento do ID: %d - %s', $integration->getId(), $integration->getQueueName()));
                    $this->executeIntegration($integration);
                } catch (Exception $e) {
                    $statusError = $this->statusService->discoveryStatus('pending', 'error', 'integration');
                    $output->writeln(sprintf('<error>Erro ao processar o ID: %d. Erro: %s</error>', $integration->getId(), $e->getMessage()));
                    $integration->setStatus($statusError);
                    $this->entityManager->persist($integration);
                } finally {
                    $this->entityManager->flush();
                }
            }

            $output->writeln('Verificação da fila de integração concluída.');
            $lock->release();
            return Command::SUCCESS;
        } else {
            $output->writeln('Outro processo ainda está em execução. Ignorando...');
            return Command::SUCCESS;
        }
    }
}
