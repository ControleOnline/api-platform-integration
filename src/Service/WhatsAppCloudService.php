<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Connection;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Phone;
use ControleOnline\Entity\TaskInteration;
use ControleOnline\Message\TranscribeAudioMessage;
use ControleOnline\WhatsApp\Messages\WhatsAppContent;
use ControleOnline\WhatsApp\Messages\WhatsAppMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Integration queue handler (queueName=WhatsAppCloud) for inbound Meta Cloud API messages.
 *
 * The receiving number is found by phone_number_id; routing (AI first, then
 * connection type) lives in CustomerConversationRouter.
 */
class WhatsAppCloudService
{
    public function __construct(
        private EntityManagerInterface $manager,
        private CustomerConversationRouter $router,
        private WhatsAppCloudMediaService $media,
        private ConversationRealtimeNotifier $realtime,
        private AudioTranscriptionService $transcription,
        private MessageBusInterface $bus,
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

        $interaction = $this->router->receive($message, $connection, (string) ($data['name'] ?? ''));
        if ($interaction && !empty($data['media']['id'])) {
            $this->attachMedia($interaction, $connection, $data);
        }

        return $interaction;
    }

    /**
     * Images, audios, videos and documents become the interaction file (the conversation shows the
     * player/preview). Audios are sent to api-whisper; the text comes back to /webhook/transcription.
     * A failed download keeps the "[type]" placeholder so the team still sees that something arrived.
     */
    private function attachMedia(TaskInteration $interaction, Connection $connection, array $data): void
    {
        try {
            $file = $this->media->storeInbound($connection, $interaction->getRegisteredBy(), $data['media']);
        } catch (\Throwable) {
            $file = null;
        }
        if (!$file) {
            $interaction->setBody((string) ($data['text'] ?? ''));
            $this->manager->flush();

            return;
        }
        $interaction->setFile($file);
        $this->manager->flush();

        $task = $interaction->getTask();
        $this->realtime->notify($connection->getPeople(), $task, [
            'event' => 'conversation_message',
            'channel' => 'whatsapp',
            'status' => 'updated',
            'interaction_id' => $interaction->getId(),
        ]);

        if ($this->transcription->isEnabled() && $this->transcription->isAudio($file) && !empty($data['api_base'])) {
            try {
                $this->bus->dispatch(new TranscribeAudioMessage(
                    (int) $file->getId(),
                    (string) ($data['domain'] ?? ''),
                    rtrim((string) $data['api_base'], '/') . '/webhook/transcription',
                ));
            } catch (\Throwable) {
                // Transcription is optional.
            }
        }
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
        // With media the file is attached afterwards; the body keeps only the caption.
        $content->setBody(!empty($data['media']['id']) ? (string) ($data['caption'] ?? '') : (string) ($data['text'] ?? ''));

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
