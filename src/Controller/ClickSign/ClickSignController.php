<?php

namespace ControleOnline\Controller\ClickSign;

use ControleOnline\Service\IntegrationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use ControleOnline\Service\LoggerService;
use ControleOnline\Service\WhatsAppService;
use Symfony\Component\HttpFoundation\JsonResponse;
use ControleOnline\Service\RequestPayloadService;

class ClickSignController extends AbstractController
{
    protected static $logger;

    public function __construct(
        private LoggerService $loggerService,
        private RequestPayloadService $requestPayloadService,
        public WhatsAppService $whatsAppService
    ) {
        self::$logger = $loggerService->getLogger('ClickSign');
    }

    #[Route('/webhook/clicksign', name: 'clicksign_webhook', methods: ['POST'])]
    #[Security("is_granted('ROLE_HUMAN')")]
    public function handleClickSignWebhook(
        Request $request,
        IntegrationService $integrationService
    ): Response {
        $rawInput = $request->getContent();
        $event = $this->requestPayloadService->decodeJsonContent($rawInput);

        $integrationService->addIntegration($rawInput, 'ClickSign');
        self::$logger->info('Evento enviado para a fila', ['event' => $event]);

        return new JsonResponse(['accepted'], Response::HTTP_ACCEPTED);
    }
}
