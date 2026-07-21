<?php

namespace ControleOnline\Integration\Tests\Service;

use ControleOnline\Entity\Integration;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\LoggerService;
use ControleOnline\Service\WebhookCaptureService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class WebhookCaptureServiceTest extends TestCase
{
    public function testCapturesMercadoLivreNotificationWithProviderScopedEventId(): void
    {
        $rawBody = json_encode([
            'resource' => '/items/MLB123',
            'topic' => 'items',
        ], JSON_THROW_ON_ERROR);

        $integration = new Integration();
        $this->setIntegrationId($integration, 77);

        $integrationService = $this->createMock(IntegrationService::class);
        $integrationService
            ->expects(self::once())
            ->method('findRecentIntegrationIdByWebhookEvent')
            ->with('WebhookCapture', 'mercadolivre:/items/MLB123')
            ->willReturn(null);
        $integrationService
            ->expects(self::once())
            ->method('addIntegrationWithHeaders')
            ->with(
                self::callback(static function (string $body): bool {
                    $decoded = json_decode($body, true);

                    return ($decoded['provider'] ?? null) === 'MercadoLivre'
                        && ($decoded['payload']['resource'] ?? null) === '/items/MLB123'
                        && ($decoded['__webhook']['event_type'] ?? null) === 'items';
                }),
                'WebhookCapture',
                self::callback(static fn (array $headers): bool => (
                    ($headers['webhook']['provider'] ?? null) === 'MercadoLivre'
                    && ($headers['webhook']['event_id'] ?? null) === 'mercadolivre:/items/MLB123'
                ))
            )
            ->willReturn($integration);

        $service = $this->createService($integrationService);
        $result = $service->capture(
            Request::create('/oauth/mercadolivre/notifications', 'POST', [], [], [], [], $rawBody),
            'MercadoLivre'
        );

        self::assertSame(77, $result->getId());
    }

    public function testCapturesGithubDeliveryWithoutDependingOnTableRows(): void
    {
        $rawBody = json_encode([
            'action' => 'opened',
            'repository' => ['full_name' => 'controleonline/api'],
        ], JSON_THROW_ON_ERROR);

        $integration = new Integration();
        $this->setIntegrationId($integration, 88);

        $integrationService = $this->createMock(IntegrationService::class);
        $integrationService
            ->expects(self::once())
            ->method('findRecentIntegrationIdByWebhookEvent')
            ->with('WebhookCapture', 'github:delivery-1')
            ->willReturn(null);
        $integrationService
            ->expects(self::once())
            ->method('addIntegrationWithHeaders')
            ->with(
                self::anything(),
                'WebhookCapture',
                self::callback(static fn (array $headers): bool => (
                    ($headers['webhook']['provider'] ?? null) === 'GitHub'
                    && ($headers['webhook']['event_id'] ?? null) === 'github:delivery-1'
                    && ($headers['webhook']['event_type'] ?? null) === 'pull_request'
                    && ($headers['webhook']['resource'] ?? null) === 'controleonline/api'
                ))
            )
            ->willReturn($integration);

        $request = Request::create(
            '/webhook/github',
            'POST',
            [],
            [],
            [],
            [
                'HTTP_X_GITHUB_DELIVERY' => 'delivery-1',
                'HTTP_X_GITHUB_EVENT' => 'pull_request',
            ],
            $rawBody,
        );

        $service = $this->createService($integrationService);
        $result = $service->capture($request, 'GitHub');

        self::assertSame(88, $result->getId());
    }

    private function createService(IntegrationService $integrationService): WebhookCaptureService
    {
        $loggerService = $this->createMock(LoggerService::class);
        $loggerService
            ->method('getLogger')
            ->willReturn(new NullLogger());

        return new WebhookCaptureService($integrationService, $loggerService);
    }

    private function setIntegrationId(Integration $integration, int $id): void
    {
        $property = new \ReflectionProperty(Integration::class, 'id');
        $property->setAccessible(true);
        $property->setValue($integration, $id);
    }
}
