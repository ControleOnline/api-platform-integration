<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\File;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Task;
use ControleOnline\Entity\TaskInteration;
use ControleOnline\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use ControleOnline\WhatsApp\Messages\WhatsAppContent;
use ControleOnline\WhatsApp\Messages\WhatsAppMedia;
use ControleOnline\WhatsApp\Messages\WhatsAppMessage;
use ControleOnline\WhatsApp\Profile\WhatsAppProfile;
use ControleOnline\WhatsApp\WhatsAppClient;
use ControleOnline\Entity\Connection;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Phone;

/**
 * WhatsApp integration transport.
 *
 * Keeps the Integration table as the queue. Transport is chosen by Connection.channel:
 * - whatsapp (default): WHATSAPP_SERVER / Baileys via WhatsAppClient
 * - whatsapp_official: Meta Cloud API called directly from PHP (no Node hop)
 */
class WhatsAppService
{
    /** @var array<string, WhatsAppClient> */
    private static array $clients = [];

    public function __construct(
        private EntityManagerInterface $manager,
        private TaskInterationService $taskInterationService,
        private ?CustomerConversationRouter $router = null,
        private ?WhatsAppCloudMediaService $cloudMedia = null,
    ) {
    }

    private function getUnofficialApiKey(): string
    {
        $whatsAppKey = $this->manager->getRepository(User::class)->findOneBy([
            'username' => 'WhatsApp',
        ]);

        if (!$whatsAppKey) {
            throw new \Exception('WhatsApp key not found');
        }

        return $whatsAppKey->getApiKey();
    }

    /**
     * Resolve client from Connection.channel.
     * Official uses Meta token + phone_number_id from channel_config.
     */
    private function getWhatsAppClient(?Connection $connection = null): WhatsAppClient
    {
        $isOfficial = $connection
            && method_exists($connection, 'isOfficialWhatsApp')
            && $connection->isOfficialWhatsApp();

        if ($isOfficial) {
            $config = method_exists($connection, 'getChannelConfigArray')
                ? $connection->getChannelConfigArray()
                : [];
            $token = (string) ($config['access_token'] ?? $config['accessToken'] ?? '');
            $phoneNumberId = (string) ($config['phone_number_id'] ?? $config['phoneNumberId'] ?? '');
            $apiVersion = (string) ($config['api_version'] ?? $config['apiVersion'] ?? 'v22.0');
            $graphHost = (string) ($config['graph_host'] ?? 'https://graph.facebook.com');

            if ($token === '' || $phoneNumberId === '') {
                throw new \RuntimeException(
                    'Official WhatsApp connection is missing access_token or phone_number_id in channel_config.'
                );
            }

            $cacheKey = 'official:' . $connection->getId() . ':' . substr(hash('sha256', $token), 0, 12);
            if (!isset(self::$clients[$cacheKey])) {
                self::$clients[$cacheKey] = new WhatsAppClient(
                    $graphHost,
                    $token,
                    WhatsAppClient::PROVIDER_OFFICIAL,
                    $phoneNumberId,
                    $apiVersion
                );
            }

            return self::$clients[$cacheKey];
        }

        $cacheKey = 'unofficial:default';
        if (!isset(self::$clients[$cacheKey])) {
            $server = $_ENV['WHATSAPP_SERVER'] ?? $_SERVER['WHATSAPP_SERVER'] ?? getenv('WHATSAPP_SERVER') ?: '';
            if ($server === '') {
                throw new \RuntimeException('WHATSAPP_SERVER is not configured');
            }
            self::$clients[$cacheKey] = new WhatsAppClient(
                $server,
                $this->getUnofficialApiKey(),
                WhatsAppClient::PROVIDER_UNOFFICIAL
            );
        }

        return self::$clients[$cacheKey];
    }

    public function integrate(Integration $integration)
    {
        $message = json_decode($integration->getBody(), true);

        $messageContent = new WhatsAppContent();
        $messageContent->setBody($message['message'] ?? '');

        $whatsAppMessage = new WhatsAppMessage();
        $whatsAppMessage->setAction($message['action'] ?? 'sendMessage');
        $whatsAppMessage->setOriginNumber($message['origin'] ?? '');
        $whatsAppMessage->setDestinationNumber($message['destination'] ?? '');
        $whatsAppMessage->setMessageContent($messageContent);

        $action = $whatsAppMessage->getAction();
        if ($action !== 'sendMessage' && $action !== 'sendMedia') {
            return $this->processMessage($whatsAppMessage);
        }

        $connection = $this->resolveConnectionForMessage($whatsAppMessage);
        $isOfficial = $connection && method_exists($connection, 'isOfficialWhatsApp') && $connection->isOfficialWhatsApp();
        $file = !empty($message['file_id']) ? $this->manager->getRepository(File::class)->find((int) $message['file_id']) : null;

        try {
            if ($isOfficial && $file instanceof File && $this->cloudMedia) {
                $result = $this->cloudMedia->sendFile($connection, $whatsAppMessage->getDestinationNumber(), $file, (string) ($message['message'] ?? ''));
                if (($result['success'] ?? false) && ($result['pending_text'] ?? '') !== '') {
                    $messageContent->setBody($result['pending_text']);
                    $result = $this->processMessage($whatsAppMessage);
                }
            } else {
                $result = $this->processMessage($whatsAppMessage);
            }
        } catch (\Throwable $e) {
            $result = ['success' => false, 'message' => $e->getMessage()];
        }

        if ($isOfficial && is_array($result) && array_key_exists('success', $result) && !$result['success']) {
            $this->reportFailure($message, $result);
        }

        return $result;
    }

    /**
     * The customer did not get the reply: tell the team with a private note on the request (it also
     * pushes the responsible person). Meta only accepts free-form replies up to 24h after the
     * customer's last message (error 131047); after that a pre-approved template is required.
     */
    private function reportFailure(array $message, array $result): void
    {
        $task = !empty($message['task_id']) ? $this->manager->getRepository(Task::class)->find((int) $message['task_id']) : null;
        if (!$task instanceof Task) {
            return;
        }
        $code = (int) ($result['code'] ?? ($result['raw']['error']['code'] ?? 0));
        $detail = (string) ($result['message'] ?? 'erro desconhecido');
        $text = $code === 131047
            ? 'Mensagem não entregue no WhatsApp: passaram mais de 24h desde a última mensagem do cliente. Peça para ele escrever de novo (ou use um modelo aprovado pela Meta).'
            : 'Mensagem não entregue no WhatsApp: ' . $detail;

        $note = new TaskInteration();
        $note->setTask($task);
        $note->setType('comment');
        $note->setVisibility('private');
        $note->setBody($text);
        $note->setRegisteredBy($task->getProvider());
        $this->manager->persist($note);
        $this->manager->flush();
    }

    public function searchConnectionFromPeople(People $people, string $type, $mainConnection = false): ?Connection
    {
        return $this->taskInterationService->searchConnectionFromPeople($people, $type, $mainConnection);
    }

    public function createSession(string $phoneNumber)
    {
        $whatsAppProfile = new WhatsAppProfile();
        $whatsAppProfile->setPhoneNumber($phoneNumber);

        $connection = null;
        try {
            $connection = $this->getConnectionFromProfile($whatsAppProfile);
        } catch (\Throwable $e) {
            $connection = null;
        }

        return $this->getWhatsAppClient($connection)->createSession($whatsAppProfile);
    }

    private function receiveMessage(WhatsAppMessage $whatsAppMessage)
    {
        $whatsAppProfile = new WhatsAppProfile();
        $whatsAppProfile->setPhoneNumber($whatsAppMessage->getDestinationNumber());
        $connection = $this->getConnectionFromProfile($whatsAppProfile);

        // Same flow as the official API: AI first (if configured), else connection type.
        if ($this->router) {
            return $this->router->receive($whatsAppMessage, $connection);
        }
        switch ($connection->getType()) {
            case 'support':
                return $this->taskInterationService->addClientInteration($whatsAppMessage, $connection, 'support');
            case 'relationship':
                return $this->taskInterationService->addClientInteration($whatsAppMessage, $connection, 'relationship');
        }

        return null;
    }

    private function getConnectionFromProfile(WhatsAppProfile $profile): Connection
    {
        $phone = $this->manager->getRepository(Phone::class)->findOneBy([
            'phone' => substr($profile->getPhoneNumber(), 4),
            'ddd' => substr($profile->getPhoneNumber(), 2, 2),
            'ddi' => substr($profile->getPhoneNumber(), 0, 2),
        ]);

        $connection = $this->manager->getRepository(Connection::class)->findOneBy([
            'phone' => $phone,
            'channel' => Connection::CHANNEL_WHATSAPP,
        ]);

        if (!$connection) {
            // also try official channel for same phone
            $connection = $this->manager->getRepository(Connection::class)->findOneBy([
                'phone' => $phone,
                'channel' => Connection::CHANNEL_WHATSAPP_OFFICIAL,
            ]);
        }

        if (!$connection) {
            // fallback: any whatsapp* channel for phone
            $connection = $this->manager->getRepository(Connection::class)->findOneBy([
                'phone' => $phone,
            ]);
        }

        if (!$connection) {
            throw new \RuntimeException('WhatsApp connection not found for origin number');
        }

        return $connection;
    }

    private function resolveConnectionForMessage(WhatsAppMessage $whatsAppMessage): ?Connection
    {
        try {
            $profile = new WhatsAppProfile();
            $profile->setPhoneNumber($whatsAppMessage->getOriginNumber());

            return $this->getConnectionFromProfile($profile);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function processMessage(WhatsAppMessage $whatsAppMessage)
    {
        $whatsAppMessage->validate();
        $content = $whatsAppMessage->getMessageContent();
        $message = json_decode((string) $content->getBody(), true);
        if (!is_array($message)) {
            $message = [];
        }

        $connection = $this->resolveConnectionForMessage($whatsAppMessage);
        $client = $this->getWhatsAppClient($connection);

        switch ($whatsAppMessage->getAction()) {
            case 'sendMessage':
                return $client->send($whatsAppMessage);
            case 'sendMedia':
                $media = new WhatsAppMedia();
                $media->setData($message['file'] ?? null);
                $content->setMedia($media);

                return $client->sendMedia($whatsAppMessage);
            case 'receiveMessage':
                return $this->receiveMessage($whatsAppMessage);
            default:
                return null;
        }
    }
}
