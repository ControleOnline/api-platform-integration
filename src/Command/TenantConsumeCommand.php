<?php

namespace ControleOnline\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Command\Command;

#[AsCommand(
    name: 'tenant:messenger:consume',
    description: 'Consume mensagens com suporte a multi-tenancy'
)]
class TenantConsumeCommand extends DefaultCommand
{
    public function __construct(
        $lockFactory,
        $databaseSwitchService,
        private $application // importante
    ) {
        $this->lockFactory = $lockFactory;
        $this->databaseSwitchService = $databaseSwitchService;

        parent::__construct('tenant:messenger:consume');
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('receivers', InputArgument::IS_ARRAY, 'Receivers (ex: async)')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL)
            ->addOption('memory-limit', null, InputOption::VALUE_OPTIONAL)
            ->addOption('time-limit', null, InputOption::VALUE_OPTIONAL)
            ->addOption('sleep', null, InputOption::VALUE_OPTIONAL)
            ->addOption('bus', null, InputOption::VALUE_OPTIONAL)
            ->addOption('queues', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY);
    }

    protected function runCommand(): int
    {
        $receivers = $this->input->getArgument('receivers') ?: ['async'];

        $this->addLog(sprintf(
            '[tenant:messenger:consume] Iniciando | domain=%s | receivers=%s',
            $this->input->getOption('domain') ?: 'all',
            implode(',', $receivers)
        ));

        $command = $this->getApplication()->find('messenger:consume');

        $newInput = new ArrayInput([
            'receivers' => $receivers,
            '--limit' => $this->input->getOption('limit'),
            '--memory-limit' => $this->input->getOption('memory-limit'),
            '--time-limit' => $this->input->getOption('time-limit'),
            '--sleep' => $this->input->getOption('sleep'),
            '--bus' => $this->input->getOption('bus'),
            '--queues' => $this->input->getOption('queues'),
        ]);

        $newInput->setInteractive(false);

        return $command->run($newInput, $this->output);
    }
}
