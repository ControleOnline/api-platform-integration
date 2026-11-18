<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Config;
use ControleOnline\Entity\Connection;
use ControleOnline\Entity\People;
use ControleOnline\Entity\TaskInteration;
use ControleOnline\Messages\MessageInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Single entry for customer messages from any connection (WhatsApp official, WhatsApp QR, e-mail).
 *
 * - Company with an AI configured: the message joins/opens the conversation with the AI assigned;
 *   the AI answers, classifies, and hands off to relationship (CRM) or support when needed.
 * - No AI: legacy behaviour by connections.type (support/relationship), humans answer.
 * Employees follow everything in CRM / Support (and prompt the AI in /ai).
 */
class CustomerConversationRouter
{
    public const AI_CONFIG_KEY = 'ai-providers';

    public function __construct(
        private EntityManagerInterface $manager,
        private TaskInterationService $taskInterationService,
        private IntegrationService $integrationService,
        private ConversationRealtimeNotifier $realtime,
    ) {
    }

    public function receive(MessageInterface $message, Connection $connection, string $name = ''): ?TaskInteration
    {
        $company = $connection->getPeople();
        $isEmail = method_exists($connection, 'isEmail') && $connection->isEmail();
        $isOfficial = method_exists($connection, 'isOfficialWhatsApp') && $connection->isOfficialWhatsApp();
        // Official WhatsApp and e-mail (app-community#1643): the request opens in the connection
        // context (relationship -> CRM distribution, support -> support rule); the AI only answers
        // when someone triggers it on the request ("Acionar IA" sets tasks.ai_agent).
        $aiAvailable = ($isOfficial || $isEmail) ? false : $this->companyHasAi($company);

        // Keep legacy automations for QR WhatsApp companies without AI.
        if (!$isOfficial && !$aiAvailable && !$isEmail && in_array($connection->getType(), ['support', 'relationship'], true)) {
            $interaction = $this->taskInterationService->addClientInteration($message, $connection, $connection->getType());
        } else {
            $interaction = $this->taskInterationService->receiveCustomerMessage($message, $connection, $name, $aiAvailable);
        }

        $task = $interaction->getTask();
        $this->realtime->notify($company, $task, [
            'event' => 'conversation_message',
            'channel' => $isEmail ? 'email' : 'whatsapp',
            'status' => 'received',
            'interaction_id' => $interaction->getId(),
        ]);

        if (method_exists($task, 'getAiAgent') && $task->getAiAgent()) {
            $this->integrationService->addIntegration((string) json_encode([
                'action' => 'mcp_chat',
                'channel' => 'customer',
                'trigger' => 'customer',
                'task_id' => $task->getId(),
                'interaction_id' => $interaction->getId(),
                'company_id' => $company->getId(),
                'provider' => $task->getAiAgent() !== 'auto' ? $task->getAiAgent() : null,
            ], JSON_UNESCAPED_UNICODE), 'McpChat', null, null, $company);
        }

        return $interaction;
    }

    /** An enabled provider with an API key in the company's ai-providers config. */
    public function companyHasAi(People $company): bool
    {
        $row = $this->manager->getRepository(Config::class)->findOneBy([
            'people' => $company,
            'configKey' => self::AI_CONFIG_KEY,
        ]);
        $providers = $row ? json_decode((string) $row->getConfigValue(), true) : null;
        foreach (is_array($providers) ? $providers : [] as $cfg) {
            if (is_array($cfg) && !empty($cfg['enabled']) && !empty($cfg['api_key'])) {
                return true;
            }
        }

        return false;
    }
}
