<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Task;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use ControleOnline\Entity\Connection;
use ControleOnline\Messages\MessageInterface;

class N8NService
{

    public function __construct(
        private HttpClientInterface $n8nClient,
        private EntityManagerInterface $entityManager,
        private SkyNetService $skyNetService,
    ) {}

    public function sendToWebhook(MessageInterface $message, Connection $connection, Task $task)
    {
        return $this->n8nClient->request('POST', 'init', [
            'json' => [
                'origin' => $message->getOriginNumber(),
                'destination' => $message->getDestinationNumber(),
                'message' => $message->getMessageContent()->getBody(),
                'action' => $message->getAction(),
                'task' => [
                    'id' => $task->getId(),
                    'type' => $task->getType(),
                    'name' => $task->getName(),
                    'type' => $task->getType(),
                    'dueDate' => $task->getDueDate() ? $task->getDueDate()->format('Y-m-d H:i:s') : null,
                    'taskStatus' => $task->getTaskStatus() ? $task->getTaskStatus()->getStatus() : null,
                    'category' => $task->getCategory() ? $task->getCategory()->getName() : null,
                    'reason' => $task->getReason() ? $task->getReason()->getName() : null,
                    'criticality' => $task->getCriticality() ? $task->getCriticality()->getLevel() : null,
                    'createdAt' => $task->getCreatedAt() ? $task->getCreatedAt()->format('Y-m-d H:i:s') : null,
                    'alterDate' => $task->getAlterDate() ? $task->getAlterDate()->format('Y-m-d H:i:s') : null,
                    'order' => [
                        'id' => $task->getOrder() ? $task->getOrder()->getId() : null,
                        'code' => $task->getOrder() ? $task->getOrder()->getCode() : null,
                    ],
                    'provider' => [
                        'id' => $task->getProvider() ? $task->getProvider()->getId() : null,
                        'name' => $task->getProvider() ? $task->getProvider()->getName() : null,
                    ],
                    'client' => [
                        'id' => $task->getClient() ? $task->getClient()->getId() : null,
                        'name' => $task->getClient() ? $task->getClient()->getName() : null,
                    ],
                ],
                'connection' => [
                    'id' => $connection->getId(),
                    'type' => $connection->getType(),
                    'name' => $connection->getName(),
                    'people' => $connection->getPeople() ? $connection->getPeople()->getId() : null,
                    'status' => $connection->getStatus() ? $connection->getStatus()->getStatus() : null,
                    'channel' => $connection->getChannel(),
                    'phone' => [
                        'phone' => $connection->getPhone() ? $connection->getPhone()->getPhone() : null,
                        'ddd' => $connection->getPhone() ? $connection->getPhone()->getDdd() : null,
                        'ddi' => $connection->getPhone() ? $connection->getPhone()->getDdi() : null,
                    ]
                ],
            ]
        ]);
    }
}
