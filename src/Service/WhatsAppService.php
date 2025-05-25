<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Integration;
use ControleOnline\Entity\User;
use ControleOnline\Service\DomainService;
use ControleOnline\Service\InvoiceService;
use ControleOnline\Service\OrderService;
use ControleOnline\Service\PeopleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface
as Security;
use ControleOnline\Service\PeopleRoleService;
use ControleOnline\Service\WalletService;
use ControleOnline\WhatsApp\Messages\WhatsAppContent;
use ControleOnline\WhatsApp\Messages\WhatsAppMedia;
use ControleOnline\WhatsApp\Messages\WhatsAppMessage;
use ControleOnline\WhatsApp\Profile\WhatsAppProfile;
use ControleOnline\WhatsApp\WhatsAppClient;
use ControleOnline\Entity\Connection;

class WhatsAppService
{
    private static $whatsAppClient;
    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private PeopleRoleService $peopleRoleService,
        private DomainService $domainService,
        private PeopleService $peopleService,
        private InvoiceService $invoiceService,
        private OrderService $orderService,
        private WalletService $walletService,
        private StatusService $statusService,

    ) {
        if (!self::$whatsAppClient)
            self::$whatsAppClient = new WhatsAppClient($_ENV['WHATSAPP_SERVER'], $this->getApiKey());
    }

    private function getApiKey()
    {
        $whatsAppKey = $this->manager->getRepository(User::class)->findOneBy([
            'username' => 'WhatsApp',
        ]);

        if (!$whatsAppKey) throw new \Exception('WhatsApp key not found');

        return $whatsAppKey->getApiKey();
    }

    public function integrate(Integration $integration)
    {
        $message = json_decode($integration->getBody(), true);

        $messageContent = new WhatsAppContent();
        $messageContent->setBody($message['message']);

        $whatsAppMessage = new WhatsAppMessage();
        $whatsAppMessage->setOriginNumber($message['origin']);
        $whatsAppMessage->setDestinationNumber($message['destination']);
        $whatsAppMessage->setMessageContent($messageContent);

        $this->processMessage($whatsAppMessage);
    }

    public function createSession(string $phoneNumber)
    {
        $whatsAppProfile = new WhatsAppProfile();
        $whatsAppProfile->setPhoneNumber($phoneNumber);

        return self::$whatsAppClient->createSession($whatsAppProfile);
    }

    private function receiveMessage(WhatsAppMessage $whatsAppMessage)
    {
        $whatsAppProfile = new WhatsAppProfile();
        $content = $whatsAppMessage->getMessageContent();
        $whatsAppProfile->setPhoneNumber($content['destination']);

        $connection = $this->getConnectionFromProfile($whatsAppProfile);
        $connection->gettype();
    }

    private function getConnectionFromProfile(WhatsAppProfile $whatsAppProfile): Connection
    {
        return $this->manager->getRepository(Connection::class)->findOneBy([
            'phone' => $whatsAppProfile->getPhoneNumber(),
            'channel' => 'whatsapp'
        ]);
    }

    public function processMessage(WhatsAppMessage $whatsAppMessage)
    {
        $content = $whatsAppMessage->getMessageContent();

        switch ($content["action"]) {
            case 'sendMessage':
                return self::$whatsAppClient->sendMessage($whatsAppMessage);
                break;
            case 'sendMedia':
                $media = new WhatsAppMedia();
                $media->setData($content['file']);
                $content->setMedia($media);
                return self::$whatsAppClient->sendMedia($whatsAppMessage);
                break;
            case 'receiveMessage':
                return $this->receiveMessage($whatsAppMessage);
                break;
            default:
                return null;
                break;
        }
    }
}
