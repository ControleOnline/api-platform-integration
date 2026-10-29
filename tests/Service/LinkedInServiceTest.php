<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\People;
use ControleOnline\Service\Client\LinkedInClient;
use ControleOnline\Service\ConfigService;
use ControleOnline\Service\LinkedInService;
use ControleOnline\Service\LoggerService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

final class LinkedInServiceTest extends TestCase
{
    public function testBuildOAuthCallbackUrl(): void
    {
        $service = $this->createService();
        self::assertSame(
            'https://api.controleonline.com/oauth/linkedin/return',
            $service->buildOAuthCallbackUrl('https://api.controleonline.com')
        );
    }

    public function testBuildAuthorizationPageRequiresClientId(): void
    {
        $config = $this->createStub(ConfigService::class);
        $config->method('getConfig')->willReturn('');

        $service = $this->createService(configService: $config);
        $provider = $this->createProvider(1);

        $result = $service->buildAuthorizationPage($provider, 'https://api.controleonline.com', null, 'erp.example.com');

        self::assertFalse($result['success']);
        self::assertSame('missing_client_id', $result['error']);
    }

    public function testBuildAuthorizationPageRequiresAppDomain(): void
    {
        $config = $this->createStub(ConfigService::class);
        $config->method('getConfig')->willReturnCallback(static function ($people, string $key): string {
            return $key === 'linkedin_client_id' ? 'client-abc' : '';
        });

        $service = $this->createService(configService: $config);
        $provider = $this->createProvider(1);

        $result = $service->buildAuthorizationPage($provider, 'https://api.controleonline.com', null, '');

        self::assertFalse($result['success']);
        self::assertSame('missing_app_domain', $result['error']);
    }

    public function testBuildAuthorizationPageSuccess(): void
    {
        $config = $this->createStub(ConfigService::class);
        $config->method('getConfig')->willReturnCallback(static function ($people, string $key): string {
            return match ($key) {
                'linkedin_client_id' => 'client-abc',
                'linkedin_client_secret' => 'secret-xyz',
                default => '',
            };
        });

        $client = $this->createMock(LinkedInClient::class);
        $client->expects(self::once())
            ->method('buildAuthorizationUrl')
            ->with(
                'client-abc',
                'https://api.controleonline.com/oauth/linkedin/return',
                self::callback(static fn (string $state): bool => str_contains($state, '.')),
                LinkedInClient::DEFAULT_SCOPES
            )
            ->willReturn([
                'authorization_url' => 'https://www.linkedin.com/oauth/v2/authorization?x=1',
                'scopes' => LinkedInClient::DEFAULT_SCOPES,
            ]);

        $service = $this->createService(client: $client, configService: $config);
        $provider = $this->createProvider(42);

        $result = $service->buildAuthorizationPage(
            $provider,
            'https://api.controleonline.com',
            'https://erp.example.com/integrations-page',
            'erp.example.com'
        );

        self::assertTrue($result['success']);
        self::assertNotEmpty($result['state']);
        self::assertStringContainsString('linkedin.com', $result['authorization_url']);
        self::assertSame(LinkedInClient::DEFAULT_SCOPES, $result['scopes']);
    }

    public function testStateRoundTripAndExpiry(): void
    {
        $service = $this->createService();
        $encode = new ReflectionMethod(LinkedInService::class, 'encodeOAuthState');
        $encode->setAccessible(true);
        $decode = new ReflectionMethod(LinkedInService::class, 'decodeOAuthState');
        $decode->setAccessible(true);

        $payload = [
            'provider_id' => 7,
            'app_domain' => 'erp.example.com',
            'redirect_uri' => 'https://api.controleonline.com/oauth/linkedin/return',
            'return_url' => '/integrations-page',
            'oauth_nonce' => 'abc',
            'issued_at' => time(),
        ];
        $state = $encode->invoke($service, $payload);
        $decoded = $decode->invoke($service, $state);

        self::assertIsArray($decoded);
        self::assertSame(7, $decoded['provider_id']);
        self::assertSame('erp.example.com', $decoded['app_domain']);

        $tampered = $state . 'x';
        self::assertNull($decode->invoke($service, $tampered));

        $expired = $encode->invoke($service, array_merge($payload, ['issued_at' => time() - 10_000]));
        self::assertNull($decode->invoke($service, $expired));
    }

    public function testConnectViaOAuthCodePersistsTokens(): void
    {
        $provider = $this->createProvider(9);
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('find')->with(9)->willReturn($provider);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->expects(self::atLeastOnce())->method('persist');
        $em->expects(self::atLeastOnce())->method('flush');

        $configEntity = new class {
            public string $value = '';

            public function setConfigValue(string $value): void
            {
                $this->value = $value;
            }
        };

        $config = $this->createMock(ConfigService::class);
        $config->method('getConfig')->willReturnCallback(static function ($people, string $key): string {
            return match ($key) {
                'linkedin_client_id' => 'client-abc',
                'linkedin_client_secret' => 'secret-xyz',
                default => '',
            };
        });
        $config->method('discoveryConfig')->willReturn($configEntity);

        $client = $this->createMock(LinkedInClient::class);
        $client->method('exchangeAuthorizationCode')->willReturn([
            'access_token' => 'at-1',
            'refresh_token' => 'rt-1',
            'expires_in' => 3600,
            'scope' => LinkedInClient::DEFAULT_SCOPES,
        ]);
        $client->method('getUserInfo')->willReturn([
            'sub' => 'member-99',
            'name' => 'Jane Doe',
        ]);

        $service = $this->createService(client: $client, configService: $config, entityManager: $em);

        $encode = new ReflectionMethod(LinkedInService::class, 'encodeOAuthState');
        $encode->setAccessible(true);
        $state = $encode->invoke($service, [
            'provider_id' => 9,
            'app_domain' => 'erp.example.com',
            'redirect_uri' => 'https://api.controleonline.com/oauth/linkedin/return',
            'return_url' => 'https://erp.example.com/integrations-page',
            'oauth_nonce' => 'n1',
            'issued_at' => time(),
        ]);

        $result = $service->connectViaOAuthCode(
            'auth-code',
            $state,
            'https://api.controleonline.com/oauth/linkedin/return',
            'erp.example.com'
        );

        self::assertTrue($result['success']);
        self::assertSame('member-99', $result['member_id']);
        self::assertSame('Jane Doe', $result['member_name']);
        self::assertSame('at-1', $configEntity->value); // last write (scopes) — at least one write happened
    }

    public function testGetConnectionStatusDisconnected(): void
    {
        $config = $this->createStub(ConfigService::class);
        $config->method('getConfig')->willReturn('');

        $service = $this->createService(configService: $config);
        $status = $service->getConnectionStatus($this->createProvider(1));

        self::assertFalse($status['connected']);
        self::assertFalse($status['has_token']);
        self::assertFalse($status['client_configured']);
    }

    public function testDisconnectClearsConfigs(): void
    {
        $written = [];
        $configEntity = new class($written) {
            public function __construct(private array &$written)
            {
            }

            public function setConfigValue(string $value): void
            {
                $this->written[] = $value;
            }
        };

        $config = $this->createMock(ConfigService::class);
        $config->method('discoveryConfig')->willReturnCallback(static function () use ($configEntity) {
            return $configEntity;
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::atLeast(6))->method('persist');
        $em->expects(self::atLeast(6))->method('flush');

        $service = $this->createService(configService: $config, entityManager: $em);
        $result = $service->disconnect($this->createProvider(3));

        self::assertTrue($result['success']);
        self::assertContains('', $written);
    }

    private function createProvider(int $id): People
    {
        $people = $this->getMockBuilder(People::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $people->method('getId')->willReturn($id);

        return $people;
    }

    private function createService(
        ?LinkedInClient $client = null,
        ?ConfigService $configService = null,
        ?EntityManagerInterface $entityManager = null,
    ): LinkedInService {
        $loggerService = $this->createStub(LoggerService::class);
        $loggerService->method('getLogger')->willReturn(new NullLogger());

        return new LinkedInService(
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $client ?? $this->createStub(LinkedInClient::class),
            $configService ?? $this->createStub(ConfigService::class),
            $loggerService,
        );
    }
}
