<?php

namespace ControleOnline\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ControleOnline\MessageHandler\iFood\OrderMessageHandler;
use ControleOnline\Message\iFood\OrderMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class IntegrationRetryCommand extends Command
{
    private Connection $connection;
    private OrderMessageHandler $orderMessageHandler;
    private MessageBusInterface $bus;

    public function __construct(Connection $connection, OrderMessageHandler $orderMessageHandler, MessageBusInterface $bus)
    {
        $this->connection = $connection;
        $this->orderMessageHandler = $orderMessageHandler;
        $this->bus = $bus;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('integration:retry')
            ->setDescription('Reprocessa mensagens falhadas');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Recuperar as mensagens falhadas da tabela
        $query = 'SELECT * FROM integration WHERE queue_status = :status';
        $messages = $this->connection->fetchAllAssociative($query, ['status' => 'error']);

        foreach ($messages as $message) {
            // Aqui você precisa recuperar os dados que o handler precisa, como o 'OrderMessage'
            $event = unserialize($message['message_data']); // Ajuste conforme a forma como os dados são armazenados

            // Criar o OrderMessage com os dados recuperados
            $orderMessage = new OrderMessage($event);

            // Criar a Envelope (necessária para o Messenger processar a mensagem)
            $envelope = new Envelope($orderMessage);

            // Chamar o handler diretamente
            $this->orderMessageHandler->__invoke($orderMessage);

            $output->writeln("Mensagem reprocessada: {$message['id']}");
        }

        return Command::SUCCESS;
    }
}
