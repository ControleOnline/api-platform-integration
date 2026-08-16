<?php

namespace ControleOnline\Integration\Tests\Controller\Webhook;

use ControleOnline\Controller\Webhook\CaptureController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

final class CaptureControllerTest extends TestCase
{
    public function testGithubWebhookKeepsLegacyProductionPath(): void
    {
        $method = new \ReflectionMethod(CaptureController::class, 'github');
        $routes = array_map(
            static fn (\ReflectionAttribute $attribute): Route => $attribute->newInstance(),
            $method->getAttributes(Route::class),
        );

        $paths = array_map(
            static fn (Route $route): string => $route->getPath(),
            $routes,
        );

        self::assertContains('/oauth/github/notifications', $paths);
        self::assertContains('/webhook/github', $paths);
        self::assertContains('/github/webhook', $paths);

        foreach ($routes as $route) {
            self::assertSame(['POST'], $route->getMethods());
        }
    }
}
