<?php

namespace ControleOnline\Command;

use ControleOnline\Service\EmailInboundService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Polls every e-mail connection (IMAP) and queues new customer e-mails (queue EmailInbound).
 * Register it as a cron job (e.g. every 2 minutes): email:fetch
 */
#[AsCommand(name: 'email:fetch', description: 'Le e-mails nao lidos das conexoes de e-mail e envia para o fluxo de conversas.')]
class EmailFetchCommand extends Command
{
    public function __construct(private EmailInboundService $emailInboundService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Max e-mails per connection', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $failed = 0;
        foreach ($this->emailInboundService->emailConnections() as $connection) {
            try {
                $queued = $this->emailInboundService->fetch($connection, $limit);
                $output->writeln(sprintf('connection %d: %d e-mail(s) queued', $connection->getId(), $queued));
            } catch (\Throwable $e) {
                $failed++;
                // Never print credentials; the IMAP error text has none.
                $output->writeln(sprintf('<error>connection %d: %s</error>', $connection->getId(), $e->getMessage()));
            }
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
