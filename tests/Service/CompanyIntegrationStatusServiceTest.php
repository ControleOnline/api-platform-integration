<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\People;
use ControleOnline\Service\CompanyIntegrationStatusService;
use ControleOnline\Service\ConfigService;
use PHPUnit\Framework\TestCase;

class CompanyIntegrationStatusServiceTest extends TestCase
{
    public function testListCompanyIntegrationsMarksEachProviderAccordingToRequiredConfigKeys(): void
    {
        $provider = $this->createMock(People::class);
        $provider->method('getId')->willReturn(123);

        $configService = $this->createMock(ConfigService::class);
        $configService->method('getConfig')->willReturnCallback(
            static function (People $people, string $key, bool $json = false) {
                return match ($key) {
                    'OAUTH_UBER_APP_ID' => 'uber-app-id',
                    'OAUTH_UBER_CLIENT_SECRET' => 'uber-secret',
                    'OAUTH_UBER_STORE_ID' => 'uber-store-id',
                    'asaas-key' => 'asaas-key',
                    'asaas-receiver-pix-key' => '',
                    'clicksign-key' => 'clicksign-key',
                    default => null,
                };
            }
        );

        $service = new CompanyIntegrationStatusService($configService);
        $items = $service->listCompanyIntegrations($provider);
        $indexed = [];

        foreach ($items as $item) {
            $indexed[$item['key']] = $item;
        }

        self::assertSame(['OAUTH_UBER_STORE_ID'], $indexed['uber']['required_config_keys']);
        self::assertSame(['asaas-key', 'asaas-receiver-pix-key'], $indexed['asaas']['required_config_keys']);
        self::assertSame(['clicksign-key'], $indexed['clicksign']['required_config_keys']);
        self::assertTrue($indexed['uber']['connected']);
        self::assertFalse($indexed['asaas']['connected']);
        self::assertTrue($indexed['clicksign']['connected']);
    }
}
