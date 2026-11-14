<?php

namespace ControleOnline\Controller\WhatsApp;

use ControleOnline\Service\ConfigService;
use ControleOnline\Service\DomainService;
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
 * Both live in the config table of the API domain's company
 * (whatsapp-cloud-verify-token, whatsapp-cloud-app-secret); env vars are a fallback.
 * Each inbound message becomes one row in `integration` (queue WhatsAppCloud),
 * deduplicated by wamid because Meta retries deliveries.
 */
class WhatsAppCloudWebhookController
{
    public const QUEUE = 'WhatsAppCloud';
    public const CONFIG_VERIFY_TOKEN = 'whatsapp-cloud-verify-token';
    public const CONFIG_APP_SECRET = 'whatsapp-cloud-app-secret';

    public function __construct(
        private IntegrationService $integrationService,
        private LoggerService $loggerService,
        private ConfigService $configService,
        private DomainService $domainService,
    ) {
    }

    #[Route('/webhook/whatsapp-cloud', name: 'whatsapp_cloud_webhook_verify', methods: ['GET'])]
    public function verify(Request $request): Response
    {
        $expected = $this->secret(self::CONFIG_VERIFY_TOKEN, 'WHATSAPP_CLOUD_VERIFY_TOKEN');
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
            $this->secret(self::CONFIG_APP_SECRET, 'WHATSAPP_CLOUD_APP_SECRET'),
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

    private function secret(string $configKey, string $envKey): string
    {
        try {
            $company = $this->domainService->getPeopleDomain()->getPeople();
            $value = trim((string) $this->configService->getConfig($company, $configKey));
            // Tolerate values saved JSON-encoded by the config UI.
            $value = trim($value, '"');
            if ($value !== '') {
                return $value;
            }
        } catch (\Throwable) {
            // No domain/config available: fall back to the environment.
        }

        return self::env($envKey);
    }

    private static function env(string $key): string
    {
        return trim((string) ($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: ''));
    }
}
