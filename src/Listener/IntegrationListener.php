<?php

namespace ControleOnline\Listener;

use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

class IntegrationListener
{
    public function __construct(private Connection $connection) {}

    // Evento para quando uma mensagem falha
    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        $stamp = $event->getEnvelope()->last(TransportMessageIdStamp::class);
        $id = $stamp?->getId();

        if ($id) {
            $this->connection->executeStatement(
                'UPDATE integration SET status = :status WHERE id = :id',
                ['status' => 'error', 'id' => $id]
            );
        }
    }

    // Evento para quando uma mensagem é processada com sucesso
    public function onMessageHandled(WorkerMessageHandledEvent $event): void
    {
        $stamp = $event->getEnvelope()->last(TransportMessageIdStamp::class);
        $id = $stamp?->getId();

        if ($id) {
            $this->connection->executeStatement(
                'UPDATE integration SET status = :status WHERE id = :id',
                ['status' => 'success', 'id' => $id]
            );
        }
    }

}
