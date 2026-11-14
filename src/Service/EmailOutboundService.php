<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Connection;
use ControleOnline\Entity\Integration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Integration queue handler (queueName=EmailOutbound): replies to customers through the
 * SMTP of the e-mail connection the conversation came in (queued by TaskInterationService::notifyClient).
 */
class EmailOutboundService
{
    public function __construct(private EntityManagerInterface $manager)
    {
    }

    public function integrate(Integration $integration): array
    {
        $data = json_decode((string) $integration->getBody(), true);
        $connection = is_array($data) ? $this->manager->getRepository(Connection::class)->find((int) ($data['connection_id'] ?? 0)) : null;
        if (!$connection instanceof Connection) {
            throw new \RuntimeException('EmailOutbound: connection not found');
        }
        $config = $connection->getChannelConfigArray();
        $from = (string) ($config['from_address'] ?? $config['username'] ?? '');
        $to = (string) ($data['to'] ?? '');
        if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('EmailOutbound: invalid from/to address');
        }

        $text = (string) ($data['message'] ?? '');
        $email = (new Email())
            ->from(new Address($from, (string) ($config['from_name'] ?? '')))
            ->to($to)
            ->subject((string) ($data['subject'] ?? ''))
            ->text($text)
            ->html(nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')));

        Transport::fromDsn(self::smtpDsn($config))->send($email);

        return ['sent' => true, 'to' => $to];
    }

    public static function smtpDsn(array $config): string
    {
        if (!empty($config['smtp_dsn'])) {
            return (string) $config['smtp_dsn'];
        }
        $scheme = ($config['smtp_encryption'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp';

        return sprintf(
            '%s://%s:%s@%s:%d',
            $scheme,
            rawurlencode((string) ($config['smtp_username'] ?? $config['username'] ?? '')),
            rawurlencode((string) ($config['smtp_password'] ?? $config['password'] ?? '')),
            (string) ($config['smtp_host'] ?? ''),
            (int) ($config['smtp_port'] ?? ($scheme === 'smtps' ? 465 : 587)),
        );
    }
}
