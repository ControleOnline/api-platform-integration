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
use ControleOnline\Entity\Connection;
use ControleOnline\Messages\MessagesInterface;
use ControleOnline\Messages\ProfileInterface;

class AutomationMessagesService
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

    ) {}
    public function receiveMessage(MessagesInterface $Message, ProfileInterface $profile)
    {
        $content = $Message->getMessageContent();

        $connection = $this->getConnectionFromProfile($profile);
        $connection->gettype();
    }

    private function getConnectionFromProfile(ProfileInterface $profile): Connection
    {
        return $this->manager->getRepository(Connection::class)->findOneBy([
            'phone' => $profile->getPhoneNumber(),
            'channel' => 'whatsapp'
        ]);
    }
}
