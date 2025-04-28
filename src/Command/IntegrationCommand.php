<?php

namespace ControleOnline\Command;

use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Status;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;

class IntegrationCommand extends Command
{
    protected static $defaultName = 'app:process-integration-queue';

    public function __construct(
        private IntegrationService $integrationService,
        private EntityManagerInterface $entityManager,
        private StatusService $statusService,
        private LockFactory $lockFactory
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Consulta a tabela de integração e processa registros com status pendente.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lock = $this->lockFactory->createLock('integration');

        if ($lock->acquire()) {
            $output->writeln('Iniciando a verificação da fila de integração...');

            $integrationRepository = $this->entityManager->getRepository(Integration::class);
            $statusOpen = $this->statusService->discoveryStatus('open', 'open', 'integration');
            $statusClosed = $this->statusService->discoveryStatus('closed', 'closed', 'integration');
            $statusError = $this->statusService->discoveryStatus('pending', 'error', 'integration');

            $integrations = $integrationRepository->findBy(['status' => $statusOpen], null, 1000);

            foreach ($integrations as $integration) {
                try {
                    $output->writeln(sprintf('Iniciando o processamento do ID: %d', $integration->getId()));

                    $this->integrationService->execute($integration);
                    
                    $integration->setStatus($statusClosed);
                    $this->entityManager->flush();
                } catch (\Exception $e) {
                    $output->writeln(sprintf('<error>Erro ao processar o ID: %d. Erro: %s</error>', $integration->getId(), $e->getMessage()));
                    $integration->setStatus($statusError);
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
