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
        self::$logger = $loggerService->getLogger('99Food');
    }

    #[Route('/webhook/99food', name: '99food_webhook', methods: ['POST'])]
    public function handle99FoodWebhook(
        Request $request,
        IntegrationService $integrationService
    ): Response {
        $rawInput = $request->getContent();
        $signature = $request->headers->get('didi-header-sign');

        $event = json_decode($rawInput, true);

        $integrationService->addIntegration($rawInput, '99Food');
        self::$logger->info('Evento enviado para a fila', ['event' => $event]);

        return new Response('[accepted]', Response::HTTP_ACCEPTED);
    }
}
