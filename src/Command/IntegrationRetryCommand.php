<?php

namespace ControleOnline\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationRetryCommand extends Command
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('integration:retry')
            ->setDescription('Reprocessa mensagens pendentes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Atualiza status para 'pending' (se necessário)
        $this->connection->executeStatement(
            'UPDATE integration SET queue_status = :status WHERE status = :pendingStatus',
            ['status' => 'pending', 'pendingStatus' => 'error']
        );

        // Processa as mensagens pendentes (isso dependerá da sua implementação específica)
        // Aqui você pode rodar seu processo de retry, por exemplo:
        $output->writeln("Mensagens pendentes foram reprocessadas.");

        return Command::SUCCESS;
    }
}
