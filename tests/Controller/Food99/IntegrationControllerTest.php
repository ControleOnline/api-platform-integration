<?php

namespace ControleOnline\Integration\Tests\Controller\Food99;

use ControleOnline\Controller\Food99\IntegrationController;
use ControleOnline\Entity\People;
use ControleOnline\Service\CompanyIntegrationStatusService;
use ControleOnline\Service\Food99Service;
use ControleOnline\Service\HydratorService;
use ControleOnline\Service\LoggerService;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\RequestPayloadService;
use ControleOnline\Service\iFoodService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class IntegrationControllerTest extends TestCase
{
    public function testAuthorizationPageFallsBackToMerchantManagerOverview(): void
    {
        $provider = new class extends People {
            public function getId(): ?int
            {
                return 3;
            }
        };

        $repository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $repository->expects(self::once())
            ->method('find')
            ->with(3)
            ->willReturn($provider);

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->expects(self::once())
            ->method('getRepository')
            ->with(People::class)
            ->willReturn($repository);

        $loggerService = $this->createStub(LoggerService::class);
        $loggerService->method('getLogger')
            ->willReturn(new NullLogger());

        $security = $this->createStub(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class);
        $token = $this->createStub(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class);
        $token->method('getUser')
            ->willReturn(new class($provider) implements \Symfony\Component\Security\Core\User\UserInterface {
                public function __construct(private People $provider) {}

                public function getUserIdentifier(): string
                {
                    return 'food99-user';
                }

                public function getRoles(): array
                {
                    return ['ROLE_HUMAN'];
                }

                public function eraseCredentials(): void
                {
                }

                public function getPeople(): People
                {
                    return $this->provider;
                }
            });
        $security->method('getToken')
            ->willReturn($token);

        $peopleService = $this->createMock(PeopleService::class);
        $peopleService->expects(self::never())
            ->method('canAccessCompany');

        $food99Service = $this->createMock(Food99Service::class);
        $food99Service->expects(self::once())
            ->method('__call')
            ->with(
                'getAuthorizationPage',
                self::callback(function (array $arguments): bool {
                    $payload = $arguments[0] ?? [];

                    return ($payload['provider_id'] ?? null) === 3
                        && ($payload['app_shop_id'] ?? null) === '3';
                })
            )
            ->willReturn([
                'errno' => 105,
                'errmsg' => 'Illegal cross-domain domain name',
            ]);

        $requestPayloadService = $this->createStub(RequestPayloadService::class);
        $requestPayloadService->method('decodeJsonContent')
            ->willReturnCallback(static fn (string $content): array => json_decode($content, true) ?: []);
        $requestPayloadService->method('normalizeOptionalNumericId')
            ->willReturnCallback(static function (mixed $value): ?int {
                return is_numeric($value) ? (int) $value : null;
            });

        $controller = new IntegrationController(
            $manager,
            $loggerService,
            $security,
            $peopleService,
            $food99Service,
            $this->createStub(iFoodService::class),
            $this->createStub(CompanyIntegrationStatusService::class),
            $this->createStub(HydratorService::class),
            $requestPayloadService,
        );

        $request = Request::create(
            '/marketplace/integrations/99food/store/authorization-page',
            'POST',
            [],
            [],
            [],
            [
                'HTTP_ACCEPT_LANGUAGE' => 'pt-BR,pt;q=0.9',
                'CONTENT_TYPE' => 'application/json',
            ],
            '{"provider_id":3}'
        );

        $response = $controller->getAuthorizationPage($request);
        $data = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://merchant.99app.com/pt-BR/manager/overview', $data['data']['url'] ?? null);
        self::assertTrue($data['authorization_fallback'] ?? false);
        self::assertSame('merchant-manager-overview', $data['authorization_source'] ?? null);
    }

    public function testSyncOrdersFromPollingForwardsToFood99Service(): void
    {
        $provider = new class extends People {
            public function getId(): ?int
            {
                return 3;
            }
        };

        $manager = $this->createStub(EntityManagerInterface::class);
        $loggerService = $this->createStub(LoggerService::class);
        $loggerService->method('getLogger')
            ->willReturn(new NullLogger());

        $security = $this->createStub(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class);
        $token = $this->createStub(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class);
        $token->method('getUser')
            ->willReturn(new class($provider) implements \Symfony\Component\Security\Core\User\UserInterface {
                public function __construct(private People $provider) {}

                public function getUserIdentifier(): string
                {
                    return 'food99-user';
                }

                public function getRoles(): array
                {
                    return ['ROLE_HUMAN'];
                }

                public function eraseCredentials(): void
                {
                }

                public function getPeople(): People
                {
                    return $this->provider;
                }
            });
        $security->method('getToken')
            ->willReturn($token);

        $peopleService = $this->createStub(PeopleService::class);
        $peopleService->method('canAccessCompany')
            ->willReturn(true);

        $food99Service = $this->createMock(Food99Service::class);
        $food99Service->expects(self::once())
            ->method('syncOrdersFromPolling')
            ->with($provider, null, [])
            ->willReturn([
                'errno' => 0,
                'errmsg' => '',
                'data' => [
                    'processed_order_count' => 4,
                    'failed_order_count' => 0,
                ],
            ]);

        $requestPayloadService = $this->createStub(RequestPayloadService::class);
        $requestPayloadService->method('decodeJsonContent')
            ->willReturnCallback(static fn (string $content): array => json_decode($content, true) ?: []);
        $requestPayloadService->method('normalizeOptionalNumericId')
            ->willReturnCallback(static function (mixed $value): ?int {
                return is_numeric($value) ? (int) $value : null;
            });

        $controller = new IntegrationController(
            $manager,
            $loggerService,
            $security,
            $peopleService,
            $food99Service,
            $this->createStub(iFoodService::class),
            $this->createStub(CompanyIntegrationStatusService::class),
            $this->createStub(HydratorService::class),
            $requestPayloadService,
        );

        $request = Request::create(
            '/marketplace/integrations/99food/orders/sync',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            '{}'
        );

        $response = $controller->syncOrdersFromPolling($request);
        $data = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(4, $data['data']['processed_order_count'] ?? null);
    }

    public function testSyncOrdersFromPollingForwardsTimeWindowToFood99Service(): void
    {
        $provider = new class extends People {
            public function getId(): ?int
            {
                return 3;
            }
        };

        $manager = $this->createStub(EntityManagerInterface::class);
        $loggerService = $this->createStub(LoggerService::class);
        $loggerService->method('getLogger')
            ->willReturn(new NullLogger());

        $security = $this->createStub(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class);
        $token = $this->createStub(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class);
        $token->method('getUser')
            ->willReturn(new class($provider) implements \Symfony\Component\Security\Core\User\UserInterface {
                public function __construct(private People $provider) {}

                public function getUserIdentifier(): string
                {
                    return 'food99-user';
                }

                public function getRoles(): array
                {
                    return ['ROLE_HUMAN'];
                }

                public function eraseCredentials(): void
                {
                }

                public function getPeople(): People
                {
                    return $this->provider;
                }
            });
        $security->method('getToken')
            ->willReturn($token);

        $peopleService = $this->createStub(PeopleService::class);
        $peopleService->method('canAccessCompany')
            ->willReturn(true);

        $food99Service = $this->createMock(Food99Service::class);
        $food99Service->expects(self::once())
            ->method('syncOrdersFromPolling')
            ->with(
                $provider,
                '2026-05-28 00:00:00',
                ['CREATED', 'DELIVERED'],
                '2026-05-29 00:00:00'
            )
            ->willReturn([
                'errno' => 0,
                'errmsg' => '',
                'data' => [
                    'processed_order_count' => 8,
                    'failed_order_count' => 0,
                ],
            ]);

        $requestPayloadService = $this->createStub(RequestPayloadService::class);
        $requestPayloadService->method('decodeJsonContent')
            ->willReturnCallback(static fn (string $content): array => json_decode($content, true) ?: []);
        $requestPayloadService->method('normalizeOptionalNumericId')
            ->willReturnCallback(static function (mixed $value): ?int {
                return is_numeric($value) ? (int) $value : null;
            });

        $controller = new IntegrationController(
            $manager,
            $loggerService,
            $security,
            $peopleService,
            $food99Service,
            $this->createStub(iFoodService::class),
            $this->createStub(CompanyIntegrationStatusService::class),
            $this->createStub(HydratorService::class),
            $requestPayloadService,
        );

        $request = Request::create(
            '/marketplace/integrations/99food/orders/sync',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
            ],
            json_encode([
                'from_time' => '2026-05-28 00:00:00',
                'to_time' => '2026-05-29 00:00:00',
                'event_types' => ['CREATED', 'DELIVERED'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $response = $controller->syncOrdersFromPolling($request);
        $data = json_decode((string) $response->getContent(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(8, $data['data']['processed_order_count'] ?? null);
    }
}
