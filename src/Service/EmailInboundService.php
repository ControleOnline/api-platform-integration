<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Connection;
use ControleOnline\Entity\Integration;
use ControleOnline\Service\Email\ImapClient;
use ControleOnline\Service\Email\MimeMessageParser;
use ControleOnline\WhatsApp\Messages\WhatsAppContent;
use ControleOnline\WhatsApp\Messages\WhatsAppMessage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * E-mail connections (connections.channel = email).
 *
 * channel_config: imap_host, imap_port (993), imap_encryption (ssl|none), username, password,
 * smtp_dsn (or smtp_host, smtp_port, smtp_encryption), from_address, from_name.
 *
 * fetch(): polls unseen e-mails into integration rows (queue EmailInbound, dedupe by Message-ID);
 * integrate(): EmailInbound row -> CustomerConversationRouter (same flow as WhatsApp).
 */
class EmailInboundService
{
    public const QUEUE = 'EmailInbound';

    public function __construct(
        private EntityManagerInterface $manager,
        private IntegrationService $integrationService,
        private CustomerConversationRouter $router,
    ) {
    }

    /** @return list<Connection> */
    public function emailConnections(): array
    {
        return $this->manager->getRepository(Connection::class)->findBy(['channel' => Connection::CHANNEL_EMAIL]);
    }

    public function fetch(Connection $connection, int $limit = 50): int
    {
        $config = $connection->getChannelConfigArray();
        $client = new ImapClient();
        $client->connect(
            (string) ($config['imap_host'] ?? ''),
            (int) ($config['imap_port'] ?? 993),
            (string) ($config['imap_encryption'] ?? 'ssl'),
        );
        $queued = 0;
        try {
            $client->login((string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''));
            $client->select((string) ($config['imap_mailbox'] ?? 'INBOX'));
            $own = strtolower((string) ($config['from_address'] ?? $config['username'] ?? ''));

            foreach (array_slice($client->searchUnseen(), 0, $limit) as $uid) {
                $mail = MimeMessageParser::parse($client->fetchRaw($uid));
                $eventId = $mail['message_id'] ?: ('uid:' . $connection->getId() . ':' . $uid);
                $skip = $mail['from_email'] === '' || $mail['from_email'] === $own || $mail['text'] === ''
                    || $this->integrationService->findRecentIntegrationIdByWebhookEvent(self::QUEUE, $eventId);
                if (!$skip) {
                    $this->integrationService->addIntegrationWithHeaders(
                        (string) json_encode(['connection_id' => $connection->getId()] + $mail, JSON_UNESCAPED_UNICODE),
                        self::QUEUE,
                        ['webhook' => ['event_id' => $eventId, 'source' => 'imap']],
                        null,
                        null,
                        $connection->getPeople(),
                    );
                    $queued++;
                }
                $client->markSeen($uid);
            }
        } finally {
            $client->logout();
        }

        return $queued;
    }

    public function integrate(Integration $integration): mixed
    {
        $data = json_decode((string) $integration->getBody(), true);
        $connection = is_array($data) ? $this->manager->getRepository(Connection::class)->find((int) ($data['connection_id'] ?? 0)) : null;
        if (!$connection instanceof Connection) {
            throw new \RuntimeException('EmailInbound: connection not found');
        }

        $text = trim((string) $data['text']);
        $subject = trim((string) ($data['subject'] ?? ''));
        $content = new WhatsAppContent(); // generic message DTO shared with WhatsApp
        $content->setBody($subject !== '' && !preg_match('/^(re|res|fw|fwd|enc):/i', $subject) ? "[{$subject}]\n{$text}" : $text);

        $message = new WhatsAppMessage();
        $message->setAction('receiveMessage');
        $message->setOriginNumber((string) $data['from_email']);
        $message->setDestinationNumber((string) ($connection->getChannelConfigArray()['from_address'] ?? ''));
        $message->setMessageId((string) ($data['message_id'] ?? ''));
        $message->setMessageContent($content);

        return $this->router->receive($message, $connection, (string) ($data['from_name'] ?? ''));
    }
}
