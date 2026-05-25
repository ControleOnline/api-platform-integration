<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Device;
use ControleOnline\Entity\DeviceConfig;
use ControleOnline\Entity\Address;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\People;
use ControleOnline\Service\Client\WebsocketClient;
use ControleOnline\Service\DefaultFoodService;
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

        $websocketClient
            ->expects(self::exactly(2))
            ->method('push')
            ->willReturnCallback(function (Device $device, string $message) use (&$sentMessages): Integration {
                $sentMessages[] = [$device, $message];

                return new Integration();
            });

        $this->setObjectProperty(DefaultFoodService::class, $service, 'entityManager', $entityManager);
        $this->setObjectProperty(DefaultFoodService::class, $service, 'websocketClient', $websocketClient);

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

        self::assertCount(21, $parameters);
        self::assertSame(17, $constructor->getNumberOfRequiredParameters());
        self::assertTrue($parameters[17]->isOptional());
        self::assertTrue($parameters[18]->isOptional());
        self::assertTrue($parameters[19]->isOptional());
        self::assertTrue($parameters[20]->isOptional());
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
}
