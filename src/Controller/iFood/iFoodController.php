<?php

namespace ControleOnline\Controller\iFood;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\LoggerService;

class iFoodController extends AbstractController
{
    protected static $logger;

    public function __construct(
        private LoggerService $loggerService,
    ) {
        self::$logger = $loggerService->getLogger('ifood');
    }

    #[Route('/webhook/ifood', name: 'ifood_webhook', methods: ['POST'])]
    public function handleIFoodWebhook(
        Request $request,
        IntegrationService $integrationService
    ): Response {
        $rawInput = $request->getContent();
        $signature = $request->headers->get('X-IFood-Signature');

        $secretKey = $_ENV['IFOOD_SECRET'];
        $expectedSignature = hash_hmac('sha256', $rawInput, $secretKey);

        if ($signature !== $expectedSignature) {
            self::$logger->error('Assinatura inválida', ['signature' => $signature]);
            return new Response('Invalid signature', Response::HTTP_UNAUTHORIZED);
        }

        $event = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::$logger->error('Erro ao decodificar JSON', ['error' => json_last_error_msg()]);
            return new Response('Invalid JSON', Response::HTTP_BAD_REQUEST);
        }
        if (isset($event['code']) && $event['code'] === 'KEEPALIVE') {
            self::$logger->info('Evento keepalive ignorado', ['event' => $event]);
            return new Response('[accepted]', Response::HTTP_ACCEPTED);
        }

        $integrationService->addIntegration($rawInput, 'iFood');
        self::$logger->info('Evento enviado para a fila', ['event' => $event]);

        return new Response('[accepted]', Response::HTTP_ACCEPTED);
    }
}
