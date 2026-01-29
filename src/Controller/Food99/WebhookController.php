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
        $signature = $request->headers->get('didi-header-sign');
        $appSecret = $_ENV['OAUTH_99FOOD_CLIENT_SECRET'];

        if (!$signature) {
            return new Response('Missing signature', Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            return new Response('Invalid payload', Response::HTTP_BAD_REQUEST);
        }

        $params = $data;
        unset($params['sign']);

        ksort($params);

        $signArr = [];
        foreach ($params as $k => $v) {
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $signArr[] = $k . '=' . $v;
        }

        $toSign = implode('&', $signArr) . $appSecret;
        $expectedSign = md5($toSign);

        if ($expectedSign !== $signature) {
            self::$logger->warning('Assinatura inválida', [
                'expected' => $expectedSign,
                'received' => $signature
            ]);
            return new Response('Invalid signature', Response::HTTP_UNAUTHORIZED);
        }

        $integrationService->addIntegration($rawInput, 'Food99');
        self::$logger->info('Webhook autenticado e enviado para a fila', ['event' => $data]);

        return new Response('[accepted]', Response::HTTP_ACCEPTED);
    }
}
