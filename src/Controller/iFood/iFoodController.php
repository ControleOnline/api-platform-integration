<?php

namespace ControleOnline\Controller\iFood;

use ControleOnline\Entity\Config;
use ControleOnline\Entity\People;
use ControleOnline\Service\ConfigService;
use ControleOnline\Service\ExtraDataService;
use ControleOnline\Service\IntegrationService;
use ControleOnline\Service\LoggerService;
use ControleOnline\Service\RequestPayloadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class iFoodController extends AbstractController
{
    private const APP_CONTEXT = 'iFood';
    private const CLIENT_SECRET_CONFIG_KEY = 'OAUTH_IFOOD_CLIENT_SECRET';
    private const WEBHOOK_SECRET_CONFIG_KEY = 'IFOOD_WEBHOOK_SECRET';

    protected static $logger;

    public function __construct(
        private LoggerService $loggerService,
        private RequestPayloadService $requestPayloadService,
        private ConfigService $configService,
        private ExtraDataService $extraDataService,
        private EntityManagerInterface $entityManager,
    ) {
        self::$logger = $loggerService->getLogger('iFood');
    }

    #[Route('/webhook/ifood', name: 'ifood_webhook', methods: ['POST'])]
    public function handleIFoodWebhook(
        Request $request,
        IntegrationService $integrationService
    ): Response {
        $rawInput = $request->getContent();
        $signature = trim((string) $request->headers->get('X-IFood-Signature', ''));

        if ($signature === '') {
            self::$logger->warning('iFood webhook missing signature');
            return new Response('Invalid signature', Response::HTTP_UNAUTHORIZED);
        }

        try {
            $payload = $this->requestPayloadService->decodeJsonContent($rawInput);
        } catch (\InvalidArgumentException $exception) {
            self::$logger->error('iFood webhook JSON decode failed', [
                'error' => $exception->getMessage(),
            ]);

            return new Response('Invalid JSON', Response::HTTP_BAD_REQUEST);
        }

        $events = array_is_list($payload) ? $payload : [$payload];
        $secretKeys = $this->resolveWebhookSecrets($events);

        if ($secretKeys === []) {
            self::$logger->warning('iFood webhook secret configuration not found', [
                'merchant_ids' => $this->collectMerchantIds($events),
            ]);

            return new Response('Invalid signature', Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->matchesWebhookSignature($rawInput, $signature, $secretKeys)) {
            self::$logger->error('iFood webhook signature mismatch', [
                'received_signature' => $signature,
                'merchant_ids' => $this->collectMerchantIds($events),
            ]);

            return new Response('Invalid signature', Response::HTTP_UNAUTHORIZED);
        }

        $queued = 0;

        /* Coleta merchantIds dos eventos KEEPALIVE para sinalizar presenca.
         * Modo por aplicativo: KEEPALIVE sem merchantIds → 202 com corpo ignorado.
         * Modo por merchant:  KEEPALIVE com merchantIds → 202 com {"merchantIds":[...]}
         *                     contendo apenas os merchants que devem ficar online.
         */
        $keepaliveMerchantIds = [];
        $hasKeepalive = false;

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $eventType = strtoupper(trim((string) ($event['fullCode'] ?? $event['code'] ?? '')));

            if ($eventType === 'KEEPALIVE') {
                $hasKeepalive = true;
                if (!empty($event['merchantIds']) && is_array($event['merchantIds'])) {
                    foreach ($event['merchantIds'] as $mid) {
                        if (is_string($mid) && $mid !== '') {
                            $keepaliveMerchantIds[$mid] = true;
                        }
                    }
                }
                continue;
            }

            $meta = $this->buildWebhookMeta($event, $rawInput);
            $existingIntegrationId = $integrationService->findRecentIntegrationIdByWebhookEvent('iFood', $meta['event_id']);
            if ($existingIntegrationId !== null) {
                self::$logger->info('iFood duplicate webhook ignored', [
                    'event_id' => $meta['event_id'],
                    'existing_integration_id' => $existingIntegrationId,
                ]);
                continue;
            }

            $eventPayload = $event;
            $eventPayload['__webhook'] = $meta;

            $encodedPayload = json_encode(
                $eventPayload,
                JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
            if ($encodedPayload === false) {
                self::$logger->warning('iFood webhook payload re-encode failed', [
                    'event_id' => $meta['event_id'],
                    'json_error' => json_last_error_msg(),
                ]);

                return new Response('Invalid payload encoding', Response::HTTP_BAD_REQUEST);
            }

            $integrationService->addIntegrationWithHeaders(
                $encodedPayload,
                'iFood',
                ['webhook' => $meta]
            );

            $queued++;
        }

        /* Resposta de presenca para KEEPALIVE.
         * Modo por merchant: devolve os merchantIds recebidos (todos marcados como online).
         * Modo por aplicativo: 202 com corpo padrao (iFood ignora o corpo).
         */
        if ($hasKeepalive && !empty($keepaliveMerchantIds)) {
            return new JsonResponse(
                ['merchantIds' => array_keys($keepaliveMerchantIds)],
                Response::HTTP_ACCEPTED
            );
        }

        return new JsonResponse([
            'accepted' => true,
            'queued' => $queued,
        ], Response::HTTP_ACCEPTED);
    }

    private function resolveWebhookSecrets(array $events): array
    {
        $secrets = [];

        foreach ($this->collectMerchantIds($events) as $merchantId) {
            $provider = $this->resolveProviderByMerchantId($merchantId);
            if (!$provider instanceof People) {
                continue;
            }

            $this->appendSecret($secrets, $this->configService->getConfig($provider, self::WEBHOOK_SECRET_CONFIG_KEY));
            $this->appendSecret($secrets, $this->configService->getConfig($provider, self::CLIENT_SECRET_CONFIG_KEY));
        }

        if ($secrets === []) {
            foreach ([self::WEBHOOK_SECRET_CONFIG_KEY, self::CLIENT_SECRET_CONFIG_KEY] as $configKey) {
                foreach ($this->entityManager->getRepository(Config::class)->findBy(['configKey' => $configKey]) as $config) {
                    if ($config instanceof Config) {
                        $this->appendSecret($secrets, $config->getConfigValue());
                    }
                }
            }
        }

        if ($secrets === []) {
            $this->appendEnvironmentWebhookSecrets($secrets);
        }

        return array_values(array_unique($secrets));
    }

    private function resolveProviderByMerchantId(string $merchantId): ?People
    {
        $provider = $this->extraDataService->getEntityByExtraData(self::APP_CONTEXT, 'code', $merchantId, People::class);
        if ($provider instanceof People) {
            return $provider;
        }

        $provider = $this->extraDataService->getEntityByExtraData(self::APP_CONTEXT, 'merchant_id', $merchantId, People::class);
        if ($provider instanceof People) {
            return $provider;
        }

        return ctype_digit($merchantId)
            ? $this->entityManager->getRepository(People::class)->find((int) $merchantId)
            : null;
    }

    private function collectMerchantIds(array $events): array
    {
        $merchantIds = [];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            if (is_array($event['merchantIds'] ?? null)) {
                foreach ($event['merchantIds'] as $merchantId) {
                    $this->appendMerchantId($merchantIds, $merchantId);
                }
            }

            $this->appendMerchantId($merchantIds, $event['merchantId'] ?? null);
            $this->appendMerchantId($merchantIds, $event['merchant_id'] ?? null);

            $merchant = is_array($event['merchant'] ?? null) ? $event['merchant'] : [];
            $this->appendMerchantId($merchantIds, $merchant['id'] ?? null);
            $this->appendMerchantId($merchantIds, $merchant['merchantId'] ?? null);
            $this->appendMerchantId($merchantIds, $merchant['merchant_id'] ?? null);
        }

        return array_values(array_unique($merchantIds));
    }

    private function appendMerchantId(array &$merchantIds, mixed $value): void
    {
        if (!is_scalar($value)) {
            return;
        }

        $merchantId = trim((string) $value);
        if ($merchantId !== '') {
            $merchantIds[] = $merchantId;
        }
    }

    private function appendSecret(array &$secrets, mixed $value): void
    {
        if (!is_scalar($value)) {
            return;
        }

        $secret = trim((string) $value);
        if ($secret !== '') {
            $secrets[] = $secret;
        }
    }

    private function appendEnvironmentWebhookSecrets(array &$secrets): void
    {
        foreach ([self::WEBHOOK_SECRET_CONFIG_KEY, self::CLIENT_SECRET_CONFIG_KEY] as $key) {
            $this->appendSecret(
                $secrets,
                $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: ''
            );
        }
    }

    private function matchesWebhookSignature(string $rawInput, string $signature, array $secretKeys): bool
    {
        foreach ($secretKeys as $secretKey) {
            if (hash_equals(hash_hmac('sha256', $rawInput, $secretKey), $signature)) {
                return true;
            }
        }

        return false;
    }

    private function buildWebhookMeta(array $event, string $rawInput): array
    {
        $eventType = trim((string) ($event['fullCode'] ?? $event['code'] ?? 'UNKNOWN'));
        $orderId = trim((string) ($event['orderId'] ?? ''));
        $merchantId = trim((string) ($event['merchantId'] ?? ''));
        $eventAt = trim((string) ($event['createdAt'] ?? ''));

        $providedEventId = trim((string) (
            $event['id']
            ?? $event['eventId']
            ?? $event['event_id']
            ?? ''
        ));

        $eventId = $providedEventId;
        if ($eventId === '') {
            $eventId = implode('|', [
                $eventType !== '' ? $eventType : 'unknown',
                $merchantId !== '' ? $merchantId : 'unknown-merchant',
                $orderId !== '' ? $orderId : 'unknown-order',
                $eventAt !== '' ? $eventAt : 'unknown-time',
                substr(hash('sha256', $rawInput), 0, 16),
            ]);
        }

        return [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'event_at' => $eventAt,
            'received_at' => date('Y-m-d H:i:s'),
            'shop_id' => $merchantId,
            'order_id' => $orderId,
            'body_sha256' => hash('sha256', $rawInput),
        ];
    }
}
