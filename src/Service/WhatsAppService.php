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
use ControleOnline\WhatsApp\WhatsAppClient;

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
        if (! self::$whatsAppClient)
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
        $json = json_decode($integration->getBody(), true);

        switch ($json["action"]) {
            case 'sendMessage':
                return $this->sendMessage($json);
                break;
            case 'sendMedia':
                return $this->sendMedia($json);
                break;
            default:
                return null;
                break;
        }
    }

    private function sendMessage(array $message)
    {
        $messageContent = new WhatsAppContent();
        $messageContent->setBody($message['message']);

        $whatsAppMessage = new WhatsAppMessage();
        $whatsAppMessage->setOriginNumber($message['origin']);
        $whatsAppMessage->setDestinationNumber($message['destination']);
        $whatsAppMessage->setMessageContent($messageContent);

        return self::$whatsAppClient->sendMessage($whatsAppMessage);
    }

    private function sendMedia(array $message)
    {

        $media = new WhatsAppMedia();
        $media->setData($message['file']);

        $messageContent = new WhatsAppContent();
        $messageContent->setBody($message['message']);
        $messageContent->setMedia($media);

        $whatsAppMessage = new WhatsAppMessage();
        $whatsAppMessage->setOriginNumber($message['origin']);
        $whatsAppMessage->setDestinationNumber($message['destination']);
        $whatsAppMessage->setMessageContent($messageContent);

        return self::$whatsAppClient->sendMedia($whatsAppMessage);
    }
}
