<?php

namespace ControleOnline\Controller\Food99;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        $payload = json_decode($rawInput, true);
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $info = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];
        $shop = is_array($info['shop'] ?? null) ? $info['shop'] : (is_array($data['shop'] ?? null) ? $data['shop'] : []);

        if (!$headerSign) {
            self::$logger->warning('Food99 webhook missing signature header', [
                'content_length' => strlen($rawInput),
                'event_type' => $payload['type'] ?? null,
            ]);
            return new Response('Missing signature', Response::HTTP_UNAUTHORIZED);
        }
        $signStr = $rawInput . $appSecret;
        $checkSign = md5($signStr);
        
        if ($checkSign !== $headerSign) {
            self::$logger->warning('Invalid signature', [
                'event_type' => $payload['type'] ?? null,
                'order_id' => isset($data['order_id']) ? (string) $data['order_id'] : null,
                'order_index' => isset($info['order_index']) ? (string) $info['order_index'] : null,
                'shop_id' => isset($shop['shop_id']) ? (string) $shop['shop_id'] : null,
                'expected' => $checkSign,
                'received' => $headerSign
            ]);
            return new Response('Invalid signature', Response::HTTP_UNAUTHORIZED);
        }

        $integrationService->addIntegration($rawInput, 'Food99');
        self::$logger->info('Webhook autenticado', [
            'event_type' => $payload['type'] ?? null,
            'order_id' => isset($data['order_id']) ? (string) $data['order_id'] : null,
            'order_index' => isset($info['order_index']) ? (string) $info['order_index'] : null,
            'shop_id' => isset($shop['shop_id']) ? (string) $shop['shop_id'] : null,
            'shop_name' => $shop['shop_name'] ?? null,
        ]);


        return new JsonResponse([
            'errno' => 0,
            'errmsg' => 'ok',
        ], Response::HTTP_OK);
    }
}
