<?php

namespace ControleOnline\Command;

use ControleOnline\Entity\People;
use ControleOnline\Service\Food99Service;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'food99:reconcile', description: 'Reconcilia dados remotos da 99Food com o estado persistido localmente.')]
class Food99ReconcileCommand extends Command
{
    public function __construct(
        private Food99Service $food99Service,
        private EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('provider-id', null, InputOption::VALUE_OPTIONAL, 'ID do provider para reconciliar um unico estabelecimento')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limite de providers quando processar em lote', '100')
            ->addOption('source', null, InputOption::VALUE_OPTIONAL, 'Origem da reconciliacao para auditoria', 'scheduler');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $providerId = trim((string) $input->getOption('provider-id'));
        $limit = max(1, (int) $input->getOption('limit'));
        $source = trim((string) $input->getOption('source'));
        if ($source === '') {
            $source = 'scheduler';
        }

        $providers = [];
        if ($providerId !== '') {
            if (!ctype_digit($providerId)) {
                $output->writeln('<error>provider-id precisa ser numerico.</error>');
                return Command::INVALID;
            }

            $provider = $this->entityManager->getRepository(People::class)->find((int) $providerId);
            if (!$provider instanceof People) {
                $output->writeln(sprintf('<error>Provider %s nao encontrado.</error>', $providerId));
                return Command::FAILURE;
            }

            $providers[] = $provider;
        } else {
            $providers = $this->food99Service->listProvidersWithFood99Binding($limit);
        }

        if ($providers === []) {
            $output->writeln('<comment>Nenhum provider elegivel para reconciliacao.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Iniciando reconciliacao 99Food para %d provider(s)...', count($providers)));

        $failed = 0;
        foreach ($providers as $provider) {
            $result = $this->food99Service->reconcileProviderState($provider, $source);
            $status = (string) ($result['status'] ?? 'unknown');
            $message = (string) ($result['message'] ?? '');
            $durationMs = (string) ($result['duration_ms'] ?? '0');

            $line = sprintf(
                '[provider:%d] status=%s duration=%sms message="%s"',
                (int) $provider->getId(),
                $status,
                $durationMs,
                $message
            );

            if ($status === 'ok') {
                $output->writeln('<info>' . $line . '</info>');
            } else {
                $failed++;
                $output->writeln('<comment>' . $line . '</comment>');
            }
        }

        if ($failed > 0) {
            $output->writeln(sprintf('<comment>Reconciliacao finalizada com %d provider(s) com inconsistencias.</comment>', $failed));
            return Command::FAILURE;
        }

        $output->writeln('<info>Reconciliacao finalizada com sucesso.</info>');
        return Command::SUCCESS;
    }
}

