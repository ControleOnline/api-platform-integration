<?php

namespace ControleOnline\Controller\WhatsApp;

use ControleOnline\Service\IntegrationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use ControleOnline\Service\LoggerService;
use ControleOnline\Service\RequestPayloadService;
use ControleOnline\Service\WhatsAppService;
use Symfony\Component\HttpFoundation\JsonResponse;

class WhatsAppController extends AbstractController
{
    protected static $logger;

    public function __construct(
        private LoggerService $loggerService,
        public WhatsAppService $whatsAppService,
        private RequestPayloadService $requestPayloadService
    ) {
        self::$logger = $loggerService->getLogger('WhatsApp');
    }

    #[Route('/webhook/whatsapp', name: 'whatsapp_webhook', methods: ['POST'])]
    #[Security("is_granted('ROLE_HUMAN')")]
    public function handleWhatsappWebhook(
        Request $request,
        IntegrationService $integrationService
    ): Response {
        $rawInput = $request->getContent();
        try {
            $event = $this->requestPayloadService->decodeJsonContent($rawInput);
        } catch (\InvalidArgumentException $exception) {
            self::$logger->error('Erro ao decodificar JSON', ['error' => $exception->getMessage()]);
            return new Response('Invalid JSON', Response::HTTP_BAD_REQUEST);
        }

        $integrationService->addIntegration($rawInput, 'WhatsApp');
        self::$logger->info('Evento enviado para a fila', ['event' => $event]);

        return new JsonResponse(['accepted'], Response::HTTP_ACCEPTED);
    }



    #[Route('/whatsapp/create-session', name: 'whatsapp_session', methods: ['POST'])]
    #[Security("is_granted('ROLE_HUMAN')")]
    public function handleWhatsappCreateSession(
        Request $request,
    ): Response {
        $rawInput = $request->getContent();
        try {
            $event = $this->requestPayloadService->decodeJsonContent($rawInput);
        } catch (\InvalidArgumentException $exception) {
            self::$logger->error('Erro ao decodificar JSON', ['error' => $exception->getMessage()]);
            return new Response('Invalid JSON', Response::HTTP_BAD_REQUEST);
        }
        $phone = $event['phone'];
        $session = $this->whatsAppService->createSession($phone);

        self::$logger->info('Created a session', ['phone' => $phone]);

        return new JsonResponse($session, Response::HTTP_CREATED);
    }
}
