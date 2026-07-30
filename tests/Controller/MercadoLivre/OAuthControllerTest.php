<?php

namespace ControleOnline\Integration\Tests\Controller\MercadoLivre;

use ControleOnline\Controller\MercadoLivre\OAuthController;
use ControleOnline\Service\DatabaseSwitchService;
use ControleOnline\Service\MercadoLivreService;
use ControleOnline\Service\PeopleService;
use ControleOnline\Service\RequestPayloadService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class OAuthControllerTest extends TestCase
{
    public function testCallbackUsesFixedMercadoLivreReturnRouteAndSwitchesTenantFromState(): void
    {
        $mercadoLivreService = $this->createMock(MercadoLivreService::class);
        $mercadoLivreService
            ->expects(self::once())
            ->method('resolveOAuthAppDomain')
            ->with('encoded-state', null)
            ->willReturn('erp.jaguncos.com.br');
        $mercadoLivreService
            ->expects(self::once())
            ->method('resolveOAuthReturnUrl')
            ->with('encoded-state')
            ->willReturn('/integrations-page');
        $mercadoLivreService
            ->expects(self::once())
            ->method('connectViaOAuthCode')
            ->with(
                'auth-code',
                'encoded-state',
                'https://api.controleonline.com/oauth/mercadolivre/return',
                'erp.jaguncos.com.br'
            )
            ->willReturn([
                'success' => true,
                'return_url' => '/integrations-page',
            ]);

        $databaseSwitchService = $this->createMock(DatabaseSwitchService::class);
        $databaseSwitchService
            ->expects(self::once())
            ->method('switchDatabaseByDomain')
            ->with('erp.jaguncos.com.br');

        $controller = new OAuthController(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(TokenStorageInterface::class),
            $this->createStub(PeopleService::class),
            $this->createStub(RequestPayloadService::class),
            $mercadoLivreService,
            $databaseSwitchService,
        );

        $response = $controller->callback(Request::create(
            '/oauth/mercadolivre/return?code=auth-code&state=encoded-state',
            'GET',
            [],
            [],
            [],
            [
                'HTTPS' => 'on',
                'HTTP_HOST' => 'api.controleonline.com',
            ]
        ));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(
            '/integrations-page?mercadolivre_connected=1',
            $response->getTargetUrl()
        );
    }
}
