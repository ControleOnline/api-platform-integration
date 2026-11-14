<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Connection;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Phone;
use ControleOnline\Entity\TaskInteration;
use ControleOnline\WhatsApp\Messages\WhatsAppContent;
use ControleOnline\WhatsApp\Messages\WhatsAppMessage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Integration queue handler (queueName=WhatsAppCloud) for inbound Meta Cloud API messages.
 *
 * Routing by connections.type of the receiving number:
 * - ia: the AI is the front door. The message joins the customer's open conversation;
 *   while the task is still type "ia" an McpChat job is queued. After a handoff
 *   (relationship = sales/CRM, support = analyst) only humans answer.
 * - support / relationship: legacy human flow.
 */
class WhatsAppCloudService
{
    public function __construct(
        private EntityManagerInterface $manager,
        private TaskInterationService $taskInterationService,
        private IntegrationService $integrationService,
        private ConversationRealtimeNotifier $realtime,
    ) {
    }

    public function integrate(Integration $integration): ?TaskInteration
    {
        $data = json_decode((string) $integration->getBody(), true);
        if (!is_array($data) || empty($data['from'])) {
            throw new \RuntimeException('Invalid WhatsAppCloud integration body');
        }

        $connection = $this->findConnection((string) ($data['phone_number_id'] ?? ''));
        if (!$connection) {
            throw new \RuntimeException('No whatsapp_official connection for phone_number_id ' . ($data['phone_number_id'] ?? ''));
        }

        $this->ensureConnectionPhone($connection, (string) ($data['display_phone'] ?? ''));
        $message = $this->buildMessage($data, $connection);
        $type = (string) $connection->getType();

        $interaction = match ($type) {
            'ia' => $this->taskInterationService->addClientConversationInteration($message, $connection, (string) ($data['name'] ?? '')),
            'support', 'relationship' => $this->taskInterationService->addClientInteration($message, $connection, $type),
            default => throw new \RuntimeException("Connection type '{$type}' does not receive messages"),
        };

        $task = $interaction->getTask();
        $company = $connection->getPeople();

        $this->realtime->notify($company, $task, [
            'event' => 'conversation_message',
            'channel' => 'whatsapp',
            'status' => 'received',
            'interaction_id' => $interaction->getId(),
        ]);

        if ($task->getType() === 'ia') {
            $config = $connection->getChannelConfigArray();
            $this->integrationService->addIntegration(
                (string) json_encode([
                    'action' => 'mcp_chat',
                    'channel' => 'whatsapp',
                    'task_id' => $task->getId(),
                    'interaction_id' => $interaction->getId(),
                    'company_id' => $company->getId(),
                    'provider' => $config['ai_provider'] ?? null,
                    'message' => $interaction->getBody(),
                ], JSON_UNESCAPED_UNICODE),
                'McpChat',
                null,
                null,
                $company,
            );
        }

        return $interaction;
    }

    /**
     * The webhook is per Meta App, so the receiving number is identified by phone_number_id.
     */
    private function findConnection(string $phoneNumberId): ?Connection
    {
        if ($phoneNumberId === '') {
            return null;
        }
        $candidates = $this->manager->getRepository(Connection::class)->findBy([
            'channel' => Connection::CHANNEL_WHATSAPP_OFFICIAL,
        ]);
        foreach ($candidates as $connection) {
            $config = $connection->getChannelConfigArray();
            $id = (string) ($config['phone_number_id'] ?? $config['phoneNumberId'] ?? '');
            if ($id === $phoneNumberId) {
                return $connection;
            }
        }

        return null;
    }

    /**
     * Outbound replies (TaskInterationService::notifyClient) need connection.phone as
     * origin. Official connections saved only with phone_number_id get it from Meta's
     * display_phone_number on the first inbound message.
     */
    private function ensureConnectionPhone(Connection $connection, string $displayPhone): void
    {
        $digits = preg_replace('/\D/', '', $displayPhone);
        if ($connection->getPhone() || strlen($digits) < 12) {
            return;
        }
        $ddi = (int) substr($digits, 0, 2);
        $ddd = (int) substr($digits, 2, 2);
        $number = (int) substr($digits, 4);

        $phone = $this->manager->getRepository(Phone::class)->findOneBy(['ddi' => $ddi, 'ddd' => $ddd, 'phone' => $number]);
        if (!$phone instanceof Phone) {
            $phone = new Phone();
            $phone->setDdi($ddi);
            $phone->setDdd($ddd);
            $phone->setPhone($number);
            $phone->setPeople($connection->getPeople());
            $this->manager->persist($phone);
        }
        $connection->setPhone($phone);
        $this->manager->flush();
    }

    private function buildMessage(array $data, Connection $connection): WhatsAppMessage
    {
        $content = new WhatsAppContent();
        $content->setBody((string) ($data['text'] ?? ''));

        $phone = $connection->getPhone();
        $destination = $phone
            ? $phone->getDdi() . $phone->getDdd() . $phone->getPhone()
            : (string) ($data['display_phone'] ?? '');

        $message = new WhatsAppMessage();
        $message->setAction('receiveMessage');
        $message->setOriginNumber((string) $data['from']);
        $message->setDestinationNumber($destination);
        $message->setMessageId((string) ($data['wamid'] ?? ''));
        $message->setMessageContent($content);

        return $message;
    }
}
