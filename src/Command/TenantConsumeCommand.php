<?php

namespace ControleOnline\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Lock\LockFactory;
use ControleOnline\Service\DatabaseSwitchService;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Psr\Container\ContainerInterface;

#[AsCommand(
    name: 'tenant:messenger:consume',
    description: 'Consume mensagens com suporte a multi-tenancy'
)]
class TenantConsumeCommand extends DefaultCommand
{
    private ContainerInterface $receiverLocator;

    public function __construct(
        LockFactory $lockFactory,
        DatabaseSwitchService $databaseSwitchService,
        ContainerInterface $messengerReceiverLocator 
    ) {
        $this->lockFactory = $lockFactory;
        $this->databaseSwitchService = $databaseSwitchService;
        $this->receiverLocator = $messengerReceiverLocator;

        parent::__construct('tenant:messenger:consume');
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument('receivers', InputArgument::IS_ARRAY, 'Receivers (ex: async)')
            ->addOption('sleep', null, InputOption::VALUE_OPTIONAL, '', 1);
    }

    protected function runCommand(): int
    {
        $domain = $this->input->getOption('domain');

        if (!$domain) {
            throw new \RuntimeException('Você deve informar --domain.');
        }

        $receiversNames = $this->input->getArgument('receivers') ?: ['async'];

        $this->addLog(sprintf(
            '[tenant:messenger:consume] Iniciando | domain=%s | receivers=%s',
            $domain,
            implode(',', $receiversNames)
        ));

        $receivers = [];

        foreach ($receiversNames as $name) {
            if (!$this->receiverLocator->has($name)) {
                throw new \RuntimeException("Receiver \"$name\" não encontrado.");
            }

            $receivers[$name] = $this->receiverLocator->get($name);
        }

        $worker = new Worker(
            $receivers,
            $this->bus,
            $this->eventDispatcher
        );

        $worker->run([
            'sleep' => (int)$this->input->getOption('sleep'),
        ]);

        return Command::SUCCESS;
    }
}