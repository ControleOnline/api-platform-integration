<?php

namespace ControleOnline\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Lock\LockFactory;
use ControleOnline\Service\DatabaseSwitchService;
use ControleOnline\Service\DomainService;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\LoggerService;
use ControleOnline\Service\SkyNetService;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;

class TenantConsumeCommand extends DefaultCommand
{
    public function __construct(
        LockFactory $lockFactory,
        DatabaseSwitchService $databaseSwitchService,
        LoggerService $loggerService,
        SkyNetService $skyNetService,
        private IntegrationService $integrationService,
        private EntityManagerInterface $entityManager,
        private StatusService $statusService,
        private DomainService $domainService,
    ) {
        $this->skyNetService = $skyNetService;
        $this->lockFactory = $lockFactory;
        $this->databaseSwitchService = $databaseSwitchService;
        $this->loggerService = $loggerService;
        parent::__construct('tenant:messenger:consume');
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('receivers', InputArgument::IS_ARRAY, 'Receivers (ex: async)')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL)
            ->addOption('failure-limit', 'f', InputOption::VALUE_OPTIONAL)
            ->addOption('memory-limit', 'm', InputOption::VALUE_OPTIONAL)
            ->addOption('time-limit', 't', InputOption::VALUE_OPTIONAL)
            ->addOption('sleep', null, InputOption::VALUE_OPTIONAL)
            ->addOption('bus', 'b', InputOption::VALUE_OPTIONAL)
            ->addOption('queues', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY)
            ->addOption('no-reset', null, InputOption::VALUE_NONE)
            ->addOption('all', null, InputOption::VALUE_NONE)
            ->addOption('exclude-receivers', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY)
            ->addOption('keepalive', null, InputOption::VALUE_OPTIONAL);
    }


    protected function runCommand(): int
    {
        $domain = $this->input->getOption('domain');

        if (!$domain) {
            throw new \RuntimeException('Você deve informar --domain para consumir filas.');
        }

        $receivers = $this->input->getArgument('receivers') ?: ['async'];

        $this->addLog(sprintf(
            '[tenant:messenger:consume] Iniciando worker | domain=%s | receivers=%s',
            $domain,
            implode(',', $receivers)
        ));

    // Pega o comando real do Messenger
        /** @var ConsumeMessagesCommand $consumeCommand */
        $consumeCommand = $this->getApplication()->find('messenger:consume');

        // Prepara as opções (mantém tudo que você já passa)
        $options = [
            'receivers' => $receivers,
            '--limit'            => $this->input->getOption('limit'),
            '--failure-limit'    => $this->input->getOption('failure-limit'),
            '--memory-limit'     => $this->input->getOption('memory-limit'),
            '--time-limit'       => $this->input->getOption('time-limit'),
            '--sleep'            => $this->input->getOption('sleep'),
            '--bus'              => $this->input->getOption('bus'),
            '--queues'           => $this->input->getOption('queues'),
            '--no-reset'         => $this->input->getOption('no-reset'),
            '--all'              => $this->input->getOption('all'),
            '--exclude-receivers' => $this->input->getOption('exclude-receivers'),
            '--keepalive'        => $this->input->getOption('keepalive'),
            '--verbose'          => $this->output->getVerbosity(), // importante para logs
        ];

        // Remove valores null/false/vazios
        $options = array_filter($options, fn($v) => $v !== null && $v !== false && $v !== []);

        $newInput = new ArrayInput($options);
        $newInput->setInteractive(false);

        // Executa o consumeCommand diretamente (mantém o mesmo Output)
        return $consumeCommand->run($newInput, $this->output);
    }
}
