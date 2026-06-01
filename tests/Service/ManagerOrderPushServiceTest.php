<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\People;
use ControleOnline\Service\FirebaseCloudMessagingService;
use ControleOnline\Service\ManagerOrderPushService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ManagerOrderPushServiceTest extends TestCase
{
    public function testSendCompanyEventNotificationAcceptsOrderCanceledEvent(): void
    {
        $company = $this->createStub(People::class);
        $company->method('getId')->willReturn(88);

        $device = new Device();
        $device->setDevice('android-manager');
        $device->setMetadata([
            'pushTokens' => [
                'manager' => [
                    'android' => [
                        'deviceToken' => 'fcm-token-1',
                    ],
                ],
            ],
        ]);

        $deviceConfig = (new DeviceConfig())
            ->setPeople($company)
            ->setDevice($device)
            ->setType('MANAGER');

        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findBy'])
            ->getMock();
        $repository
            ->expects(self::once())
            ->method('findBy')
            ->with(['people' => $company, 'type' => 'MANAGER'])
            ->willReturn([$deviceConfig]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(DeviceConfig::class)
            ->willReturn($repository);

        $firebaseCloudMessagingService = $this->createMock(FirebaseCloudMessagingService::class);
        $firebaseCloudMessagingService
            ->expects(self::once())
            ->method('sendNotificationToToken')
            ->with(
                'fcm-token-1',
                'Pedido #71722 cancelado',
                'O pedido foi cancelado. Toque para ver os detalhes.',
                self::callback(static function (array $data): bool {
                    return ($data['event'] ?? null) === 'order.canceled'
                        && ($data['orderId'] ?? null) === '71722'
                        && ($data['companyId'] ?? null) === '88';
                })
            );

        $logger = $this->createStub(LoggerInterface::class);

        $service = new ManagerOrderPushService(
            $entityManager,
            $firebaseCloudMessagingService,
            $logger
        );

        self::assertSame(1, $service->sendCompanyEventNotification($company, [
            'event' => 'order.canceled',
            'orderId' => '71722',
        ]));
    }
}
