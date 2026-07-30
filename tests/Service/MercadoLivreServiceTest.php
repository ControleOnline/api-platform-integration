<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\People;
use ControleOnline\Service\Client\MercadoLivreClient;
use ControleOnline\Service\ConfigService;
use ControleOnline\Service\ExtraDataService;
use ControleOnline\Service\FileService;
use ControleOnline\Service\LoggerService;
use ControleOnline\Service\MercadoLivreService;
use ControleOnline\Service\PeopleRoleService;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\StatusService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

final class MercadoLivreServiceTest extends TestCase
{
    public function testBuildOAuthCallbackUrlUsesFixedMercadoLivreRoute(): void
    {
        $service = $this->createService();
        $method = new ReflectionMethod(MercadoLivreService::class, 'buildOAuthCallbackUrl');
        $method->setAccessible(true);

        self::assertSame(
            'https://api.controleonline.com/oauth/mercadolivre/return',
            $method->invoke($service, 'https://api.controleonline.com', 'erp.jaguncos.com.br')
        );
    }

    public function testListUserItemIdsFetchesEveryPageAndDeduplicatesResults(): void
    {
        $provider = new class extends People {
        };

        $client = $this->createMock(MercadoLivreClient::class);
        $client
            ->expects(self::exactly(2))
            ->method('searchUserItems')
            ->willReturnCallback(static function (string $userId, ?People $providerArg, int $offset, int $limit): array {
                self::assertSame('seller-1', $userId);
                self::assertInstanceOf(People::class, $providerArg);
                self::assertSame(2, $limit);

                return match ($offset) {
                    0 => [
                        'results' => ['MLB1', 'MLB2'],
                        'paging' => ['total' => 3],
                    ],
                    2 => [
                        'results' => ['MLB2', 'MLB3'],
                        'paging' => ['total' => 3],
                    ],
                    default => [
                        'results' => [],
                        'paging' => ['total' => 3],
                    ],
                };
            });

        $service = $this->createService($client);
        $method = new ReflectionMethod(MercadoLivreService::class, 'listUserItemIds');
        $method->setAccessible(true);

        self::assertSame(
            ['MLB1', 'MLB2', 'MLB3'],
            $method->invoke($service, 'seller-1', $provider, 2)
        );
    }

    private function createService(?MercadoLivreClient $client = null): MercadoLivreService
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $configService = $this->createStub(ConfigService::class);
        $extraDataService = $this->createStub(ExtraDataService::class);
        $fileService = $this->createStub(FileService::class);
        $peopleService = $this->createStub(PeopleService::class);
        $peopleRoleService = $this->createStub(PeopleRoleService::class);
        $statusService = $this->createStub(StatusService::class);

        $loggerService = $this->createStub(LoggerService::class);
        $loggerService->method('getLogger')->willReturn(new NullLogger());

        return new MercadoLivreService(
            $entityManager,
            $client ?? $this->createStub(MercadoLivreClient::class),
            $configService,
            $extraDataService,
            $fileService,
            $peopleService,
            $peopleRoleService,
            $statusService,
            $loggerService,
        );
    }
}
