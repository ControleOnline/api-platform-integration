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
use ControleOnline\Entity\People;
use ControleOnline\Entity\Phone;
use Symfony\Component\Serializer\Encoder\JsonDecode;

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
        private AutomationMessagesService $automationMessagesService,
        private TaskInterationService $taskInterationService,

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
        $whatsAppMessage->setAction($message['action']);
        $whatsAppMessage->setOriginNumber((int)$message['origin']);
        $whatsAppMessage->setDestinationNumber((int)$message['destination']);
        $whatsAppMessage->setMessageContent($messageContent);

        return $this->processMessage($whatsAppMessage);
    }
  public function searchConnectionFromPeople(People $people, string $type): ?Connection
  {
    return $this->manager->getRepository(Connection::class)->findOneBy(['type' => $type, 'people' => $people]);
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
        $whatsAppProfile->setPhoneNumber($whatsAppMessage->getDestinationNumber());
        $connection = $this->getConnectionFromProfile($whatsAppProfile);
        switch ($connection->gettype()) {
            case 'support':
                return $this->taskInterationService->addClientInteration($whatsAppMessage, $connection->getPeople(), 'support');
                break;
            case 'crm':
                return $this->taskInterationService->addClientInteration($whatsAppMessage, $connection->getPeople(), 'relationship');
                break;
            default:
                return $this->automationMessagesService->receiveMessage($whatsAppMessage, $connection);
                break;
        }
    }

    private function getConnectionFromProfile(WhatsAppProfile $profile): Connection
    {
        $phone  = $this->manager->getRepository(Phone::class)->findOneBy([
            'phone' => substr($profile->getPhoneNumber(), 4),
            'ddd' => substr($profile->getPhoneNumber(), 2, 2),
            'ddi' => substr($profile->getPhoneNumber(), 0, 2)
        ]);

        return $this->manager->getRepository(Connection::class)->findOneBy([
            'phone' => $phone,
            'channel' => 'whatsapp'
        ]);
    }

    public function processMessage(WhatsAppMessage $whatsAppMessage)
    {
        $whatsAppMessage->validate();
        $content = $whatsAppMessage->getMessageContent();
        $message = json_decode($content->getBody(), true);
        switch ($whatsAppMessage->getAction()) {
            case 'sendMessage':
                return self::$whatsAppClient->sendMessage($whatsAppMessage);
                break;
            case 'sendMedia':
                $media = new WhatsAppMedia();
                $media->setData($message['file']);
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
