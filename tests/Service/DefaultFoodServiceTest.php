<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\Address;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\DomainService;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\Client\WebsocketClient;
use ControleOnline\Service\DefaultFoodService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class DefaultFoodServiceTest extends TestCase
{
    public function testBroadcastCompanyWebsocketEventsSendsOnePayloadPerDevice(): void
    {
        $service = (new \ReflectionClass(DefaultFoodServiceProbe::class))->newInstanceWithoutConstructor();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repository = $this->getMockBuilder(EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findBy'])
            ->getMock();
        $websocketClient = $this->createMock(WebsocketClient::class);
        $integrationService = $this->createMock(IntegrationService::class);
        $company = $this->createConfiguredMock(People::class, [
            'getId' => 88,
        ]);
        $firstDevice = $this->createConfiguredMock(Device::class, [
            'getId' => 11,
        ]);
        $secondDevice = $this->createConfiguredMock(Device::class, [
            'getId' => 12,
        ]);
        $firstConfig = $this->createConfiguredMock(DeviceConfig::class, [
            'getDevice' => $firstDevice,
        ]);
        $duplicateConfig = $this->createConfiguredMock(DeviceConfig::class, [
            'getDevice' => $firstDevice,
        ]);
        $secondConfig = $this->createConfiguredMock(DeviceConfig::class, [
            'getDevice' => $secondDevice,
        ]);
        $sentMessages = [];

        $entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(DeviceConfig::class)
            ->willReturn($repository);

        $repository
            ->expects(self::once())
            ->method('findBy')
            ->with(['people' => $company])
            ->willReturn([$firstConfig, $duplicateConfig, $secondConfig]);

        $payload = [[
            'store' => 'marketplace',
            'event' => 'store.opened',
            'message' => 'Loja Central foi aberta',
        ]];

        $integrationService
            ->expects(self::once())
            ->method('addManagerPushIntegrations')
            ->with(
                json_encode($payload[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $company
            )
            ->willReturn(1);

        $websocketClient
            ->expects(self::exactly(2))
            ->method('push')
            ->willReturnCallback(function (Device $device, string $message) use (&$sentMessages): Integration {
                $sentMessages[] = [$device, $message];

                return new Integration();
            });

        $this->setObjectProperty(DefaultFoodService::class, $service, 'entityManager', $entityManager);
        $this->setObjectProperty(DefaultFoodService::class, $service, 'websocketClient', $websocketClient);
        $this->setObjectProperty(DefaultFoodService::class, $service, 'integrationService', $integrationService);

        $service->emit($company, $payload);

        self::assertCount(2, $sentMessages);
        self::assertSame($firstDevice, $sentMessages[0][0]);
        self::assertSame($secondDevice, $sentMessages[1][0]);
        self::assertSame(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $sentMessages[0][1]);
        self::assertSame(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $sentMessages[1][1]);
    }

    public function testConstructorKeepsLegacyContainerCompatibility(): void
    {
        $constructor = new \ReflectionMethod(DefaultFoodService::class, '__construct');
        $parameters = $constructor->getParameters();

        self::assertCount(23, $parameters);
        self::assertSame(18, $constructor->getNumberOfRequiredParameters());
        self::assertTrue($parameters[18]->isOptional());
        self::assertTrue($parameters[19]->isOptional());
        self::assertTrue($parameters[20]->isOptional());
        self::assertTrue($parameters[21]->isOptional());
        self::assertTrue($parameters[22]->isOptional());
    }

    public function testResolveAddressCandidateAcceptsObjectIds(): void
    {
        $service = (new \ReflectionClass(DefaultFoodServiceProbe::class))->newInstanceWithoutConstructor();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repository = $this->getMockBuilder(\Doctrine\ORM\EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find'])
            ->getMock();
        $resolvedAddress = $this->createConfiguredMock(Address::class, [
            'getId' => 2059,
        ]);

        $candidate = new class {
            public function getId(): int
            {
                return 2059;
            }
        };

        $entityManager
            ->expects(self::once())
            ->method('getRepository')
            ->with(Address::class)
            ->willReturn($repository);

        $repository
            ->expects(self::once())
            ->method('find')
            ->with(2059)
            ->willReturn($resolvedAddress);

        $this->setObjectProperty(DefaultFoodService::class, $service, 'entityManager', $entityManager);

        self::assertSame($resolvedAddress, $service->resolveAddressCandidateValue($candidate));
    }

    public function testBuildPublicFileDownloadUrlUsesMainDomainFromDomainService(): void
    {
        $service = (new \ReflectionClass(DefaultFoodServiceProbe::class))->newInstanceWithoutConstructor();

        $domainService = $this->createMock(DomainService::class);
        $domainService
            ->expects(self::once())
            ->method('getMainDomain')
            ->willReturn('api.custom-domain.test');

        $this->setObjectProperty(DefaultFoodService::class, $service, 'domainService', $domainService);

        self::assertSame(
            'https://api.custom-domain.test/files/123/download?app-domain=api.custom-domain.test',
            $service->buildPublicFileDownloadUrlValue(123)
        );
    }

    public function testApplyMarketplaceOrderDateUsesAppTimezoneAndSetsBothDates(): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('America/Sao_Paulo');

        try {
            $service = (new \ReflectionClass(DefaultFoodServiceProbe::class))->newInstanceWithoutConstructor();
            $order = new Order();

            $service->applyMarketplaceOrderDateValue($order, '2026-05-29T23:37:20.421Z');

            self::assertInstanceOf(DateTimeImmutable::class, $order->getOrderDate());
            self::assertInstanceOf(DateTimeImmutable::class, $order->getAlterDate());
            self::assertSame('2026-05-29 20:37:20', $order->getOrderDate()->format('Y-m-d H:i:s'));
            self::assertSame('2026-05-29 20:37:20', $order->getAlterDate()->format('Y-m-d H:i:s'));
            self::assertSame('America/Sao_Paulo', $order->getOrderDate()->getTimezone()->getName());
        } finally {
            date_default_timezone_set($previousTimezone);
        }
    }

    private function setObjectProperty(string $className, object $object, string $propertyName, mixed $value): void
    {
        $property = new \ReflectionProperty($className, $propertyName);
        $property->setAccessible(true);
        $property->setValue($object, $value);
    }
}

final class DefaultFoodServiceProbe extends DefaultFoodService
{
    public function emit(People $company, array $events): void
    {
        $this->broadcastCompanyWebsocketEvents($company, $events);
    }

    public function resolveAddressCandidateValue(mixed $candidate): ?Address
    {
        return $this->resolveAddressCandidate($candidate);
    }

    public function buildPublicFileDownloadUrlValue(mixed $fileId): ?string
    {
        return $this->buildPublicFileDownloadUrl($fileId);
    }

    public function applyMarketplaceOrderDateValue(Order $order, mixed $value): void
    {
        $this->applyMarketplaceOrderDate($order, $value);
    }
}
