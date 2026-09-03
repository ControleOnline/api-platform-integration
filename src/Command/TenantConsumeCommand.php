<?php

namespace ControleOnline\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
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

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('domain')) {
            return parent::execute($input, $output);
        }

        $this->input = $input;
        $this->output = $output;

        if (!$this->lock->acquire()) {
            $this->addLog('Outro processo ainda está em execução. Ignorando...');
            return Command::SUCCESS;
        }

        try {
            // One process rotates through all tenants. A short Messenger
            // slice per tenant keeps the worker responsive without opening
            // one long-lived process (and DB connection) per tenant.
            while (true) {
                $tenants = $this->databaseSwitchService->getAllTenantDomains();
                if (!$tenants) {
                    usleep(250000);
                    continue;
                }

                foreach ($tenants as $tenant) {
                    $domain = trim((string) ($tenant['app_host'] ?? ''));
                    if ($domain === '') {
                        continue;
                    }

                    $_ENV['APP_DOMAIN'] = $domain;
                    $_SERVER['APP_DOMAIN'] = $domain;
                    putenv('APP_DOMAIN=' . $domain);
                    $this->databaseSwitchService->switchDatabaseByDomain($domain);
                    $this->runCommand();
                    $this->entityManager->clear();
                    $connection = $this->entityManager->getConnection();
                    if (!$connection->isTransactionActive() && $connection->isConnected()) {
                        $connection->close();
                    }
                }
            }
        } finally {
            if ($this->lock->isAcquired()) {
                $this->lock->release();
            }
        }
    }


    protected function runCommand(): int
    {
        if (!$this->lock->acquire()) {
            $this->addLog('Outro processo ainda está em execução. Ignorando...');
            return Command::SUCCESS;
        }
        $domain = $this->input->getOption('domain');

        if (!$domain) {
            throw new \RuntimeException('Você deve informar --domain para consumir filas.');
        }

        // The central cron starts one worker per tenant. The lock must follow
        // that tenant, otherwise the first domain blocks all other consumers.
        $this->lock = $this->lockFactory->createLock(
            'tenant:messenger:consume:' . trim((string) $domain)
        );

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
            '--time-limit'       => $this->input->getOption('time-limit')
                ?: (!$this->input->getOption('domain') ? 2 : null),
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
