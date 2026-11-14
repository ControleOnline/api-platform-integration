<?php

namespace ControleOnline\Controller\WhatsApp;

use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\LoggerService;
use ControleOnline\Service\WhatsAppCloudPayloadParser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Official Meta WhatsApp Cloud API webhook (one URL per Meta App).
 *
 * Public route: authenticity comes from the verify token (GET) and the
 * X-Hub-Signature-256 HMAC of the raw body with the app secret (POST).
 * Each inbound message becomes one row in `integration` (queue WhatsAppCloud),
 * deduplicated by wamid because Meta retries deliveries.
 */
class WhatsAppCloudWebhookController
{
    public const QUEUE = 'WhatsAppCloud';

    public function __construct(
        private IntegrationService $integrationService,
        private LoggerService $loggerService,
    ) {
    }

    #[Route('/webhook/whatsapp-cloud', name: 'whatsapp_cloud_webhook_verify', methods: ['GET'])]
    public function verify(Request $request): Response
    {
        $expected = self::env('WHATSAPP_CLOUD_VERIFY_TOKEN');
        // PHP converts "hub.mode" query keys to "hub_mode".
        $mode = (string) $request->query->get('hub_mode', '');
        $token = (string) $request->query->get('hub_verify_token', '');
        $challenge = (string) $request->query->get('hub_challenge', '');

        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token)) {
            return new Response($challenge, Response::HTTP_OK, ['Content-Type' => 'text/plain']);
        }

        return new Response('Forbidden', Response::HTTP_FORBIDDEN);
    }

    #[Route('/webhook/whatsapp-cloud', name: 'whatsapp_cloud_webhook', methods: ['POST'])]
    public function receive(Request $request): Response
    {
        $logger = $this->loggerService->getLogger('WhatsAppCloud');
        $raw = $request->getContent();

        if (!WhatsAppCloudPayloadParser::isValidSignature(
            $raw,
            $request->headers->get('X-Hub-Signature-256'),
            self::env('WHATSAPP_CLOUD_APP_SECRET'),
        )) {
            $logger->warning('WhatsApp Cloud webhook: invalid or missing signature');

            return new JsonResponse(['error' => 'invalid signature'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'invalid json'], Response::HTTP_BAD_REQUEST);
        }

        $queued = 0;
        foreach (WhatsAppCloudPayloadParser::messages($payload) as $message) {
            if ($this->integrationService->findRecentIntegrationIdByWebhookEvent(self::QUEUE, $message['wamid'])) {
                continue;
            }
            $this->integrationService->addIntegrationWithHeaders(
                (string) json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                self::QUEUE,
                ['webhook' => ['event_id' => $message['wamid'], 'source' => 'meta']],
            );
            $queued++;
        }

        // Status callbacks (sent/delivered/read) are acknowledged and ignored.
        $logger->info('WhatsApp Cloud webhook accepted', ['queued' => $queued]);

        return new JsonResponse(['accepted' => $queued], Response::HTTP_OK);
    }

    private static function env(string $key): string
    {
        return trim((string) ($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: ''));
    }
}
