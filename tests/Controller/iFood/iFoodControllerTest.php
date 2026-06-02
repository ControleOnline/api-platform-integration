<?php

namespace ControleOnline\Integration\Tests\Controller\iFood;

use ControleOnline\Controller\iFood\iFoodController;
use ControleOnline\Entity\Config;
use ControleOnline\Entity\People;
use ControleOnline\Service\ConfigService;
use ControleOnline\Service\ExtraDataService;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\LoggerService;
use ControleOnline\Service\RequestPayloadService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class iFoodControllerTest extends TestCase
{
    public function testKeepaliveUsesStoreClientSecretFromConfig(): void
    {
        $merchantId = '7af240aa-969a-481b-9d7d-2cf7a95fe7df';
        $secret = 'store-secret';
        $rawInput = json_encode([
            [
                'fullCode' => 'KEEPALIVE',
                'merchantIds' => [$merchantId],
            ],
        ], JSON_THROW_ON_ERROR);

        $provider = new class extends People {
            public function getId(): int
            {
                return 2;
            }
        };

        $extraDataService = $this->createMock(ExtraDataService::class);
        $extraDataService
            ->expects(self::once())
            ->method('getEntityByExtraData')
            ->with('iFood', 'code', $merchantId, People::class)
            ->willReturn($provider);

        $configService = $this->createMock(ConfigService::class);
        $configService
            ->expects(self::exactly(2))
            ->method('getConfig')
            ->willReturnCallback(static function (People $people, string $key) use ($provider, $secret): ?string {
                self::assertSame($provider, $people);

                return $key === 'OAUTH_IFOOD_CLIENT_SECRET' ? $secret : null;
            });

        $controller = $this->createController(
            $configService,
            $extraDataService,
            $this->createMock(EntityManagerInterface::class),
        );

        $response = $controller->handleIFoodWebhook(
            $this->createSignedRequest($rawInput, $secret),
            $this->createMock(IntegrationService::class),
        );

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['merchantIds' => [$merchantId]], json_decode((string) $response->getContent(), true));
    }

    public function testKeepaliveWithoutMerchantUsesConfiguredIfoodSecret(): void
    {
        $secret = 'configured-secret';
        $rawInput = json_encode(['fullCode' => 'KEEPALIVE'], JSON_THROW_ON_ERROR);

        $config = new Config();
        $config->setConfigKey('OAUTH_IFOOD_CLIENT_SECRET');
        $config->setConfigValue($secret);

        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->expects(self::exactly(2))
            ->method('findBy')
            ->willReturnCallback(static fn (array $criteria): array => (
                ($criteria['configKey'] ?? null) === 'OAUTH_IFOOD_CLIENT_SECRET'
                    ? [$config]
                    : []
            ));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::exactly(2))
            ->method('getRepository')
            ->with(Config::class)
            ->willReturn($repository);

        $controller = $this->createController(
            $this->createMock(ConfigService::class),
            $this->createMock(ExtraDataService::class),
            $entityManager,
        );

        $response = $controller->handleIFoodWebhook(
            $this->createSignedRequest($rawInput, $secret),
            $this->createMock(IntegrationService::class),
        );

        self::assertSame(202, $response->getStatusCode());
        self::assertSame(['accepted' => true, 'queued' => 0], json_decode((string) $response->getContent(), true));
    }

    private function createController(
        ConfigService $configService,
        ExtraDataService $extraDataService,
        EntityManagerInterface $entityManager,
    ): iFoodController {
        $loggerService = $this->createMock(LoggerService::class);
        $loggerService
            ->method('getLogger')
            ->willReturn(new NullLogger());

        $requestPayloadService = $this->createMock(RequestPayloadService::class);
        $requestPayloadService
            ->method('decodeJsonContent')
            ->willReturnCallback(static fn (string $content): array => json_decode($content, true, 512, JSON_THROW_ON_ERROR));

        return new iFoodController(
            $loggerService,
            $requestPayloadService,
            $configService,
            $extraDataService,
            $entityManager,
        );
    }

    private function createSignedRequest(string $rawInput, string $secret): Request
    {
        return Request::create(
            '/webhook/ifood',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_IFOOD_SIGNATURE' => hash_hmac('sha256', $rawInput, $secret),
            ],
            $rawInput,
        );
    }
}
