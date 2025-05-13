<?php

namespace ControleOnline\Controller\N8N;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Http\Attribute\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\LoggerService;

class N8NController extends AbstractController
{
    protected static $logger;

    public function __construct(
        private LoggerService $loggerService,
    ) {
        self::$logger = $loggerService->getLogger('N8N');
    }

    #[Route('/webhook/N8N', name: 'n8n_webhook', methods: ['POST'])]
    #[Security("is_granted('ROLE_ADMIN') or is_granted('ROLE_CLIENT')")]
    public function handleN8NWebhook(
        Request $request,
        IntegrationService $integrationService
    ): Response {
        $rawInput = $request->getContent();
        $event = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::$logger->error('Erro ao decodificar JSON', ['error' => json_last_error_msg()]);
            return new Response('Invalid JSON', Response::HTTP_BAD_REQUEST);
        }
        $integrationService->addIntegration($rawInput, 'N8N');
        self::$logger->info('Evento enviado para a fila', ['event' => $event]);

        return new Response('[accepted]', Response::HTTP_ACCEPTED);
    }
}
