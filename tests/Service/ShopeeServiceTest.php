<?php

declare(strict_types=1);

namespace ControleOnline\Tests\Service;

use ControleOnline\Entity\Config;
use ControleOnline\Entity\People;
use ControleOnline\Service\Client\ShopeeClient;
use ControleOnline\Service\ConfigService;
use ControleOnline\Service\ShopeeService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class ShopeeServiceTest extends TestCase
{
    public function testStatusDisconnectedByDefault(): void
    {
        $service = $this->buildService([]);
        $provider = $this->createMock(People::class);
        $provider->method('getId')->willReturn(1);

        $status = $service->getConnectionStatus($provider);
        self::assertFalse($status['connected']);
        self::assertSame('local_config', $status['data_source']);
        self::assertSame('shopee', $status['key']);
    }

    public function testAuthorizationPageRequiresPartnerId(): void
    {
        $service = $this->buildService([]);
        $provider = $this->createMock(People::class);
        $provider->method('getId')->willReturn(1);

        $result = $service->buildAuthorizationPage($provider, 'https://api.example.com');
        self::assertFalse($result['success']);
        self::assertSame('missing_partner_id', $result['error']);
    }

    /**
     * @param array<string, string> $configs
     */
    private function buildService(array $configs): ShopeeService
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturnCallback(function (array $criteria) use ($configs) {
            $key = $criteria['configKey'] ?? null;
            if (!is_string($key) || !array_key_exists($key, $configs)) {
                return [];
            }
            $config = $this->createMock(Config::class);
            $config->method('getConfigValue')->willReturn($configs[$key]);

            return [$config];
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $client = new ShopeeClient(new \Symfony\Component\HttpClient\MockHttpClient());
        $configService = $this->createMock(ConfigService::class);

        return new ShopeeService($em, $client, $configService);
    }
}
