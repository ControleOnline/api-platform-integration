<?php

namespace ControleOnline\Controller\Food99;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\LoggerService;

class WebhookController extends AbstractController
{
    protected static $logger;

    public function __construct(
        private LoggerService $loggerService,
    ) {
        self::$logger = $loggerService->getLogger('Food99');
    }

    #[Route('/webhook/99food', name: 'Food99_webhook', methods: ['POST'])]
    public function handleFood99Webhook(
        Request $request,
        IntegrationService $integrationService
    ): Response {
        $rawInput = $request->getContent();
        $headerSign = $request->headers->get('didi-header-sign');
        $appSecret = $_ENV['OAUTH_99FOOD_CLIENT_SECRET'];

        if (!$headerSign) {
            return new Response('Missing signature', Response::HTTP_UNAUTHORIZED);
        }
        $signStr = $rawInput . $appSecret;
        $checkSign = md5($signStr);
        
        if ($checkSign !== $headerSign) {
            self::$logger->warning('Invalid signature', [
                'expected' => $checkSign,
                'received' => $headerSign
            ]);
            return new Response('Invalid signature', Response::HTTP_UNAUTHORIZED);
        }

        $integrationService->addIntegration($rawInput, 'Food99');
        self::$logger->info('Webhook autenticado');


        return new Response('[accepted]', Response::HTTP_ACCEPTED);
    }
}
