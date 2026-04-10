<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\ProductUnity;
use ControleOnline\Event\EntityChangedEvent;
use DateTime;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

class Food99Service extends DefaultFoodService implements EventSubscriberInterface
{
    private const APP_CONTEXT = 'Food99';
    private const LEGACY_ORDER_CONTEXT = 'iFood';
    private const SHOP_CANCEL_REASONS = [
        ['reason_id' => 1010, 'description' => 'Item sold out', 'applicable_to' => 'all'],
        ['reason_id' => 1020, 'description' => 'Store closed for the day', 'applicable_to' => 'all'],
        ['reason_id' => 1030, 'description' => 'Store too busy to prepare order', 'applicable_to' => 'all'],
        ['reason_id' => 1040, 'description' => 'Major accident or utility outage', 'applicable_to' => 'all'],
        ['reason_id' => 1050, 'description' => 'Canceled due to customer issue', 'applicable_to' => 'all'],
        ['reason_id' => 1060, 'description' => 'No courier available', 'applicable_to' => 'self_delivery'],
        ['reason_id' => 1070, 'description' => 'Menu needs to be updated', 'applicable_to' => 'all'],
        ['reason_id' => 1071, 'description' => 'Order is outside the delivery area', 'applicable_to' => 'self_delivery'],
        ['reason_id' => 1072, 'description' => 'Order address is in an unsafe area', 'applicable_to' => 'self_delivery'],
        ['reason_id' => 1073, 'description' => 'Suspected fraud or prank', 'applicable_to' => 'all'],
        ['reason_id' => 1074, 'description' => 'Questions about fees or promotions', 'applicable_to' => 'all'],
        ['reason_id' => 1080, 'description' => 'Other reason', 'applicable_to' => 'all'],
    ];
    private static array $authTokenCache = [];

    private function init()
    {
        self::$app = self::APP_CONTEXT;
        self::$logger = $this->loggerService->getLogger(self::$app);
        self::$foodPeople = $this->peopleService->discoveryPeople('6012920000123', null, null, '99 Food', 'J');
    }

    private function buildLogContext(?Integration $integration = null, array $json = [], array $extra = []): array
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $info = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];
        $shop = is_array($info['shop'] ?? null) ? $info['shop'] : (is_array($data['shop'] ?? null) ? $data['shop'] : []);

        return array_merge([
            'integration_id' => $integration?->getId(),
            'event_type' => $json['type'] ?? null,
            'order_id' => isset($data['order_id']) ? (string) $data['order_id'] : null,
            'order_index' => isset($info['order_index']) ? (string) $info['order_index'] : null,
            'shop_id' => isset($shop['shop_id']) ? (string) $shop['shop_id'] : null,
            'shop_name' => $shop['shop_name'] ?? null,
        ], $extra);
    }

    private function normalizeIncomingFood99Value(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function normalizeCancelReasonId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', (string) $value);
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        $reasonId = (int) $normalized;

        return $reasonId > 0 ? $reasonId : null;
    }

    private function findShopCancelReasonDefinition(int $reasonId): ?array
    {
        foreach (self::SHOP_CANCEL_REASONS as $reason) {
            if ((int) ($reason['reason_id'] ?? 0) === $reasonId) {
                return $reason;
            }
        }

        return null;
    }

    private function isCancelReasonApplicableToState(array $reason, array $state): bool
    {
        $scope = strtolower(trim((string) ($reason['applicable_to'] ?? 'all')));
        if ($scope === 'all') {
            return true;
        }

        if ($scope === 'self_delivery') {
            return !empty($state['is_store_delivery']);
        }

        return true;
    }

    private function buildShopCancelReasonListForState(array $state): array
    {
        return array_map(function (array $reason) use ($state) {
            $isApplicable = $this->isCancelReasonApplicableToState($reason, $state);

            return [
                'reason_id' => (int) $reason['reason_id'],
                'description' => (string) $reason['description'],
                'applicable_to' => (string) $reason['applicable_to'],
                'requires_description' => (int) $reason['reason_id'] === 1080,
                'applicable' => $isApplicable,
            ];
        }, self::SHOP_CANCEL_REASONS);
    }

    private function buildOrderIntegrationLockKey(string $orderId): string
    {
        return 'food99:order:' . substr(sha1($orderId), 0, 40);
    }

    private function acquireOrderIntegrationLock(string $orderId): bool
    {
        try {
            $acquired = (int) $this->entityManager->getConnection()->fetchOne(
                'SELECT GET_LOCK(:lockKey, 5)',
                ['lockKey' => $this->buildOrderIntegrationLockKey($orderId)]
            );

            if ($acquired !== 1) {
                self::$logger->warning('Food99 could not acquire order integration lock in time', [
                    'order_id' => $orderId,
                    'lock_key' => $this->buildOrderIntegrationLockKey($orderId),
                ]);
            }

            return $acquired === 1;
        } catch (\Throwable $e) {
            self::$logger->warning('Food99 could not acquire order integration lock', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function releaseOrderIntegrationLock(string $orderId): void
    {
        try {
            $this->entityManager->getConnection()->fetchOne(
                'SELECT RELEASE_LOCK(:lockKey)',
                ['lockKey' => $this->buildOrderIntegrationLockKey($orderId)]
            );
        } catch (\Throwable $e) {
            self::$logger->warning('Food99 could not release order integration lock', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sanitizePayloadForLog(array $payload): array
    {
        foreach ([
            'auth_token',
            'app_secret',
            'appSecret',
            'access_token',
            'finance_access_token',
            'deliveryCode',
            'delivery_code',
        ] as $secretKey) {
            if (isset($payload[$secretKey])) {
                $payload[$secretKey] = '***';
            }
        }

        return $payload;
    }

    private function getFood99BaseUrl(): string
    {
        return 'https://openapi.didi-food.com';
    }

    private function getFood99BorderBaseUrl(): string
    {
        return 'https://b.99app.com';
    }

    private function resolveAppId(): ?string
    {
        $appId = $_ENV['OAUTH_99FOOD_CLIENT_ID']
            ?? $_ENV['OAUTH_99FOOD_APP_ID']
            ?? null;

        if (!$appId) {
            self::$logger->warning('Food99 app_id is not configured', [
                'expected_env' => ['OAUTH_99FOOD_CLIENT_ID', 'OAUTH_99FOOD_APP_ID'],
            ]);
            return null;
        }

        return (string) $appId;
    }

    private function resolveAppSecret(): ?string
    {
        $appSecret = $_ENV['OAUTH_99FOOD_CLIENT_SECRET']
            ?? $_ENV['OAUTH_99FOOD_APP_SECRET']
            ?? null;

        if (!$appSecret) {
            self::$logger->warning('Food99 app_secret is not configured', [
                'expected_env' => ['OAUTH_99FOOD_CLIENT_SECRET', 'OAUTH_99FOOD_APP_SECRET'],
            ]);
            return null;
        }

        return (string) $appSecret;
    }

    private function resolveAppShopId(?People $provider = null): ?string
    {
        if ($provider?->getId()) {
            return (string) $provider->getId();
        }

        $appShopId = $_ENV['OAUTH_99FOOD_APP_SHOP_ID']
            ?? $_ENV['OAUTH_99FOOD_SHOP_ID']
            ?? null;

        if ($appShopId) {
            return (string) $appShopId;
        }

        if ($provider) {
            $providerCode = $this->getIntegratedStoreCode($provider);
            if ($providerCode) {
                return (string) $providerCode;
            }
        }

        self::$logger->warning('Food99 app_shop_id could not be resolved', [
            'provider_id' => $provider?->getId(),
            'expected_provider_value' => 'People.id',
            'expected_env' => ['OAUTH_99FOOD_APP_SHOP_ID', 'OAUTH_99FOOD_SHOP_ID'],
        ]);

        return null;
    }

    private function requestAuthToken(string $appId, string $appSecret, string $appShopId, bool $allowRefreshFallback = true): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $this->getFood99BaseUrl() . '/v1/auth/authtoken/get', [
                'query' => [
                    'app_id' => $appId,
                    'app_secret' => $appSecret,
                    'app_shop_id' => $appShopId,
                ],
            ]);

            $payload = $response->toArray(false);
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $authToken = $data['auth_token'] ?? null;
            $tokenExpirationTime = $data['token_expiration_time'] ?? null;
            $errno = (int) ($payload['errno'] ?? 1);
            $errmsg = (string) ($payload['errmsg'] ?? '');

            if ($errno !== 0 || !$authToken) {
                if (
                    $allowRefreshFallback
                    && in_array($errno, [10100, 10101, 10102], true)
                ) {
                    self::$logger->info('Food99 auth token request requires refresh fallback', [
                        'app_shop_id' => $appShopId,
                        'status_code' => $response->getStatusCode(),
                        'errno' => $errno,
                        'errmsg' => $errmsg,
                    ]);

                    $refreshSuccess = $this->refreshAuthToken($appId, $appSecret, $appShopId);
                    if ($refreshSuccess) {
                        return $this->requestAuthToken($appId, $appSecret, $appShopId, false);
                    }
                }

                self::$logger->error('Food99 auth token request failed', [
                    'app_shop_id' => $appShopId,
                    'status_code' => $response->getStatusCode(),
                    'errno' => $errno,
                    'errmsg' => $errmsg,
                ]);
                return null;
            }

            self::$authTokenCache[$appShopId] = [
                'auth_token' => (string) $authToken,
                'token_expiration_time' => is_numeric($tokenExpirationTime) ? (int) $tokenExpirationTime : null,
            ];

            self::$logger->info('Food99 auth token fetched', [
                'app_shop_id' => $appShopId,
                'status_code' => $response->getStatusCode(),
                'token_expiration_time' => self::$authTokenCache[$appShopId]['token_expiration_time'],
            ]);

            return self::$authTokenCache[$appShopId];
        } catch (\Throwable $e) {
            self::$logger->error('Food99 auth token request error', [
                'app_shop_id' => $appShopId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function refreshAuthToken(string $appId, string $appSecret, string $appShopId): bool
    {
        try {
            $response = $this->httpClient->request('GET', $this->getFood99BaseUrl() . '/v1/auth/authtoken/refresh', [
                'query' => [
                    'app_id' => $appId,
                    'app_secret' => $appSecret,
                    'app_shop_id' => $appShopId,
                ],
            ]);

            $payload = $response->toArray(false);
            $success = $this->isSuccessfulErrno($payload['errno'] ?? null);

            self::$logger->info('Food99 auth token refresh response', [
                'app_shop_id' => $appShopId,
                'status_code' => $response->getStatusCode(),
                'errno' => $payload['errno'] ?? null,
                'errmsg' => $payload['errmsg'] ?? null,
                'success' => $success,
            ]);

            return $success;
        } catch (\Throwable $e) {
            self::$logger->error('Food99 auth token refresh error', [
                'app_shop_id' => $appShopId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function getAccessToken(?People $provider = null): ?string
    {
        $appId = $this->resolveAppId();
        $appSecret = $this->resolveAppSecret();
        $appShopId = $this->resolveAppShopId($provider);

        if (!$appId || !$appSecret || !$appShopId) {
            return null;
        }

        $cachedToken = self::$authTokenCache[$appShopId] ?? null;
        $expirationTime = is_array($cachedToken) ? ($cachedToken['token_expiration_time'] ?? null) : null;
        $hasValidCachedToken = !empty($cachedToken['auth_token']) && (!is_numeric($expirationTime) || (int) $expirationTime > (time() + 60));

        if ($hasValidCachedToken) {
            return (string) $cachedToken['auth_token'];
        }

        if (is_numeric($expirationTime) && (int) $expirationTime <= (time() + 60)) {
            $this->refreshAuthToken($appId, $appSecret, $appShopId);
        }

        $tokenData = $this->requestAuthToken($appId, $appSecret, $appShopId);
        if (!$tokenData || empty($tokenData['auth_token'])) {
            return null;
        }

        return (string) $tokenData['auth_token'];
    }

    private function resolveAccessToken(?People $provider = null): ?string
    {
        try {
            return $this->getAccessToken($provider);
        } catch (\Throwable $e) {
            self::$logger->error('Food99 access token resolution error', [
                'provider_id' => $provider?->getId(),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function resolveIntegrationAccessToken(People $provider): ?string
    {
        $this->init();

        $appId = $this->resolveAppId();
        $appSecret = $this->resolveAppSecret();
        $appShopId = $this->resolveAppShopId($provider);

        if (!$appId || !$appSecret || !$appShopId) {
            return null;
        }

        // In the current 99Food flow, a previously generated token often requires
        // an explicit refresh before a new get succeeds after process restarts.
        $this->refreshAuthToken($appId, $appSecret, $appShopId);

        $tokenData = $this->requestAuthToken($appId, $appSecret, $appShopId, false);
        if (!$tokenData || empty($tokenData['auth_token'])) {
            $tokenData = $this->requestAuthToken($appId, $appSecret, $appShopId, true);
        }

        return !empty($tokenData['auth_token']) ? (string) $tokenData['auth_token'] : null;
    }

    public function persistIntegrationAuthError(People $provider, ?string $message = null): void
    {
        $this->init();

        $this->persistProviderLastError($provider, 'auth', $message ?: 'Nao foi possivel obter o auth_token da loja na 99Food.');
    }

    public function clearIntegrationError(People $provider): void
    {
        $this->init();

        $this->persistProviderLastError($provider, '', '');
    }


    public function readyOrder(string $orderId, ?People $provider = null): ?array
    {
        return $this->call99EndpointWithResponse('/v1/order/order/ready', [
            'order_id' => $orderId,
        ], $provider);
    }

    public function deliveredOrder(string $orderId, ?People $provider = null, array $extraPayload = []): ?array
    {
        return $this->call99EndpointWithResponse('/v1/order/order/delivered', array_merge([
            'order_id' => $orderId,
        ], $extraPayload), $provider);
    }

    public function cancelByShop(string $orderId, ?People $provider = null, ?int $reasonId = null, ?string $reason = null): ?array
    {
        $resolvedReasonId = $reasonId ?: 1080;
        $resolvedReason = trim((string) $reason);

        if ($resolvedReasonId === 1080 && $resolvedReason === '') {
            $resolvedReason = 'Cancelled by merchant system';
        }

        $payload = [
            'order_id' => $orderId,
            'reason_id' => $resolvedReasonId,
        ];

        if ($resolvedReason !== '') {
            $payload['reason'] = $resolvedReason;
        }

        return $this->call99EndpointWithResponse('/v1/order/order/cancel', $payload, $provider);
    }

    private function buildUnavailableOrderActionResponse(string $message): array
    {
        return [
            'errno' => 10001,
            'errmsg' => $message,
            'data' => [],
        ];
    }

    private function normalizeOrderActionResponse(?array $response, string $fallbackMessage): array
    {
        if (!is_array($response)) {
            return $this->buildUnavailableOrderActionResponse($fallbackMessage);
        }

        if (array_key_exists('errno', $response)) {
            return $response;
        }

        $message = trim((string) ($response['errmsg'] ?? $response['message'] ?? ''));

        return [
            'errno' => 10002,
            'errmsg' => $message !== '' ? $message : $fallbackMessage,
            'data' => $response['data'] ?? $response,
        ];
    }

    private function persistOrderActionResult(Order $order, string $action, ?array $response): array
    {
        $safeResponse = $this->normalizeOrderActionResponse(
            $response,
            'Nao foi possivel executar a acao no pedido da 99Food.'
        );

        $success = $this->isSuccessfulErrno($safeResponse['errno'] ?? null);

        $this->persistOrderIntegrationState($order, [
            'last_action' => $action,
            'last_action_at' => date('Y-m-d H:i:s'),
            'last_action_errno' => isset($safeResponse['errno']) ? (string) $safeResponse['errno'] : '',
            'last_action_message' => $safeResponse['errmsg'] ?? '',
        ]);

        $this->storeOrderRemoteSnapshot($order, 'last_action_' . $action, $safeResponse);

        if ($success) {
            if ($action === 'cancel') {
                $this->persistOrderIntegrationState($order, [
                    'remote_order_state' => 'cancelled',
                ]);
                $this->applyLocalCanceledStatus($order);
            } elseif ($action === 'ready') {
                $this->persistOrderIntegrationState($order, [
                    'remote_order_state' => 'ready',
                ]);
                $this->applyLocalReadyStatus($order);
            } elseif ($action === 'delivered') {
                $this->persistOrderIntegrationState($order, [
                    'remote_order_state' => 'delivered',
                ]);
                $this->applyLocalClosedStatus($order);
            }
        }

        $this->entityManager->flush();

        return $safeResponse;
    }

    private function normalizeDeliveryCode(?string $deliveryCode): string
    {
        $normalized = preg_replace('/\D+/', '', (string) $deliveryCode);

        return trim((string) $normalized);
    }

    private function normalizeDeliveryLocator(?string $locator): string
    {
        $normalized = preg_replace('/\D+/', '', (string) $locator);

        return trim((string) $normalized);
    }

    private function requiresStoreDeliveryCode(array $state): bool
    {
        if (empty($state['is_store_delivery'])) {
            return false;
        }

        if (!empty($state['allows_manual_delivery_completion'])) {
            return true;
        }

        return trim((string) ($state['pickup_code'] ?? '')) !== ''
            || trim((string) ($state['handover_code'] ?? '')) !== '';
    }

    private function requiresStoreDeliveryLocator(array $state): bool
    {
        if (empty($state['is_store_delivery'])) {
            return false;
        }

        return !empty($state['allows_manual_delivery_completion']);
    }

    private function persistDeliveredLocatorResult(Order $order, string $locator, array $response, array $flow = []): void
    {
        $this->persistOrderIntegrationState($order, [
            'delivery_locator_at' => date('Y-m-d H:i:s'),
            'delivery_locator_errno' => isset($response['errno']) ? (string) $response['errno'] : '',
            'delivery_locator_message' => $response['errmsg'] ?? '',
            'delivery_locator_last8' => substr($locator, -8),
            'delivery_locator_step' => $flow['step'] ?? '',
            'delivery_locator_remote_order_id' => $flow['remote_order_id'] ?? '',
            'delivery_locator_shop_id' => $flow['shop_id'] ?? '',
        ]);
    }

    private function persistDeliveredCodeResult(Order $order, string $deliveryCode, ?array $response = null): void
    {
        $safeResponse = $response ?? [
            'errno' => 0,
            'errmsg' => 'Codigo de entrega informado no PPC.',
        ];

        $this->persistOrderIntegrationState($order, [
            'delivery_validate_at' => date('Y-m-d H:i:s'),
            'delivery_validate_errno' => isset($safeResponse['errno']) ? (string) $safeResponse['errno'] : '',
            'delivery_validate_message' => $safeResponse['errmsg'] ?? '',
            'delivery_code_last4' => substr($deliveryCode, -4),
        ]);
    }

    private function call99BorderEndpointWithResponse(string $method, string $uri, array $payload = []): ?array
    {
        $url = $this->getFood99BorderBaseUrl() . $uri;
        $method = strtoupper($method);
        $requestOptions = [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ];

        if ($method === 'GET') {
            $requestOptions['query'] = $payload;
        } else {
            $requestOptions['json'] = $payload;
        }

        try {
            $startedAt = microtime(true);

            self::$logger->info('Food99 BORDER REQUEST', [
                'method' => $method,
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'api_base_url' => $this->getFood99BorderBaseUrl(),
            ]);

            $response = $this->httpClient->request($method, $url, $requestOptions);
            $result = $response->toArray(false);

            self::$logger->info('Food99 BORDER RESPONSE', [
                'method' => $method,
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'status_code' => $response->getStatusCode(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'response' => $result,
                'api_base_url' => $this->getFood99BorderBaseUrl(),
            ]);

            return $result;
        } catch (\Throwable $e) {
            self::$logger->error('Food99 BORDER ERROR', [
                'method' => $method,
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'error' => $e->getMessage(),
                'api_base_url' => $this->getFood99BorderBaseUrl(),
            ]);

            return null;
        }
    }

    private function verifyStoreDeliveryLocatorRequest(string $locator): ?array
    {
        return $this->call99BorderEndpointWithResponse('POST', '/order/border/locatorVerify', [
            'locator' => $locator,
        ]);
    }

    private function completeStoreDeliveryOrderRequest(string $orderId, string $locator, string $deliveryCode): ?array
    {
        return $this->call99BorderEndpointWithResponse('POST', '/order/border/locatorOrderComplete', [
            'orderId' => $orderId,
            'DCPickupCode' => $deliveryCode,
            'locator' => $locator,
        ]);
    }

    private function buildStoreDeliveryLocatorFlowResult(array $response, string $locator, string $expectedRemoteOrderId = '', string $expectedShopId = ''): array
    {
        $safeResponse = $this->normalizeOrderActionResponse(
            $response,
            'Nao foi possivel validar o localizador na 99Food.'
        );

        $data = is_array($safeResponse['data'] ?? null) ? $safeResponse['data'] : [];
        $flowCode = (int) ($data['code'] ?? -1);
        $flowMessage = trim((string) ($data['msg'] ?? $safeResponse['errmsg'] ?? ''));
        $verifiedRemoteOrderId = trim((string) ($data['orderId'] ?? ''));
        $verifiedShopId = trim((string) ($data['shopId'] ?? ''));

        if (!$this->isSuccessfulErrno($safeResponse['errno'] ?? null)) {
            return [
                'result' => $safeResponse,
                'flow' => [
                    'step' => 'error',
                    'locator' => $locator,
                ],
            ];
        }

        if ($expectedRemoteOrderId !== '' && $verifiedRemoteOrderId !== '' && $verifiedRemoteOrderId !== $expectedRemoteOrderId) {
            return [
                'result' => $this->buildUnavailableOrderActionResponse('O localizador informado pertence a outro pedido da 99Food.'),
                'flow' => [
                    'step' => 'error',
                    'locator' => $locator,
                    'remote_order_id' => $verifiedRemoteOrderId,
                    'shop_id' => $verifiedShopId,
                ],
            ];
        }

        if ($expectedShopId !== '' && $verifiedShopId !== '' && $verifiedShopId !== $expectedShopId) {
            return [
                'result' => $this->buildUnavailableOrderActionResponse('O localizador informado pertence a outra loja da 99Food.'),
                'flow' => [
                    'step' => 'error',
                    'locator' => $locator,
                    'remote_order_id' => $verifiedRemoteOrderId,
                    'shop_id' => $verifiedShopId,
                ],
            ];
        }

        return match ($flowCode) {
            1 => [
                'result' => [
                    'errno' => 0,
                    'errmsg' => $flowMessage !== '' ? $flowMessage : 'Localizador validado com sucesso.',
                    'data' => $data,
                ],
                'flow' => [
                    'step' => 'delivery_code',
                    'locator' => $locator,
                    'remote_order_id' => $verifiedRemoteOrderId,
                    'shop_id' => $verifiedShopId,
                ],
            ],
            2 => [
                'result' => [
                    'errno' => 0,
                    'errmsg' => $flowMessage !== '' ? $flowMessage : 'Entrega concluida com sucesso.',
                    'data' => $data,
                ],
                'flow' => [
                    'step' => 'completed',
                    'locator' => $locator,
                    'remote_order_id' => $verifiedRemoteOrderId,
                    'shop_id' => $verifiedShopId,
                ],
            ],
            3 => [
                'result' => [
                    'errno' => 10003,
                    'errmsg' => $flowMessage !== '' ? $flowMessage : 'O localizador informado nao foi reconhecido.',
                    'data' => $data,
                ],
                'flow' => [
                    'step' => 'invalid_locator',
                    'locator' => $locator,
                    'remote_order_id' => $verifiedRemoteOrderId,
                    'shop_id' => $verifiedShopId,
                ],
            ],
            default => [
                'result' => [
                    'errno' => 10004,
                    'errmsg' => $flowMessage !== '' ? $flowMessage : 'A 99Food retornou um estado inesperado na validacao do localizador.',
                    'data' => $data,
                ],
                'flow' => [
                    'step' => 'error',
                    'locator' => $locator,
                    'remote_order_id' => $verifiedRemoteOrderId,
                    'shop_id' => $verifiedShopId,
                ],
            ],
        };
    }

    public function performDeliveredLocatorVerification(Order $order, ?string $locator = null): array
    {
        $state = $this->getStoredOrderIntegrationState($order);

        if (empty($state['is_store_delivery']) || empty($state['allows_manual_delivery_completion'])) {
            return [
                'result' => $this->buildUnavailableOrderActionResponse(
                    'Esse pedido nao usa o fluxo de entrega da loja com localizador da 99Food.'
                ),
                'flow' => [
                    'step' => 'error',
                ],
            ];
        }

        $normalizedLocator = $this->normalizeDeliveryLocator($locator ?: (string) ($state['locator'] ?? ''));
        if (strlen($normalizedLocator) !== 8) {
            return [
                'result' => $this->buildUnavailableOrderActionResponse(
                    'Informe o localizador de 8 digitos para validar a entrega da 99Food.'
                ),
                'flow' => [
                    'step' => 'error',
                    'locator' => $normalizedLocator,
                ],
            ];
        }

        $expectedRemoteOrderId = trim((string) ($state['food99_id'] ?? ''));
        $expectedShopId = trim((string) ($this->getIntegratedStoreCode($order->getProvider()) ?? ''));
        $rawResponse = $this->verifyStoreDeliveryLocatorRequest($normalizedLocator);
        $verification = $this->buildStoreDeliveryLocatorFlowResult(
            $rawResponse ?? [],
            $normalizedLocator,
            $expectedRemoteOrderId,
            $expectedShopId
        );

        $this->persistDeliveredLocatorResult(
            $order,
            $normalizedLocator,
            $verification['result'],
            $verification['flow']
        );

        if ($this->isSuccessfulErrno($verification['result']['errno'] ?? null)) {
            $this->persistOrderIntegrationState($order, [
                'locator' => $normalizedLocator,
            ]);
        }

        $this->storeOrderRemoteSnapshot($order, 'delivery_locator_verify', $rawResponse ?? []);

        if (($verification['flow']['step'] ?? '') === 'completed') {
            $verification['result'] = $this->persistOrderActionResult(
                $order,
                'delivered',
                $verification['result']
            );
        } else {
            $this->entityManager->flush();
        }

        return $verification;
    }

    private function completeStoreDeliveryOrder(Order $order, string $orderId, ?string $deliveryCode = null, ?string $locator = null): array
    {
        $state = $this->getStoredOrderIntegrationState($order);
        $normalizedLocator = $this->normalizeDeliveryLocator($locator ?: (string) ($state['locator'] ?? ''));
        $normalizedDeliveryCode = $this->normalizeDeliveryCode($deliveryCode);

        if ($this->requiresStoreDeliveryLocator($state)) {
            if (strlen($normalizedLocator) !== 8) {
                return $this->buildUnavailableOrderActionResponse(
                    'Informe o localizador de 8 digitos para concluir a entrega da 99Food.'
                );
            }

            if (strlen($normalizedDeliveryCode) !== 4) {
                return $this->buildUnavailableOrderActionResponse(
                    'Informe o codigo de 4 digitos do cliente para concluir a entrega da 99Food.'
                );
            }

            $this->persistDeliveredCodeResult($order, $normalizedDeliveryCode);

            $response = $this->normalizeOrderActionResponse(
                $this->completeStoreDeliveryOrderRequest($orderId, $normalizedLocator, $normalizedDeliveryCode),
                'Nao foi possivel concluir a entrega da loja na 99Food.'
            );

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $completed = $this->isSuccessfulErrno($response['errno'] ?? null) && (int) ($data['code'] ?? 0) === 1;

            if (!$completed) {
                $result = [
                    'errno' => 10005,
                    'errmsg' => trim((string) ($data['msg'] ?? $response['errmsg'] ?? 'Nao foi possivel concluir a entrega da loja na 99Food.')),
                    'data' => $data,
                ];
                $this->persistDeliveredCodeResult($order, $normalizedDeliveryCode, $result);

                return $result;
            }

            $result = [
                'errno' => 0,
                'errmsg' => trim((string) ($data['msg'] ?? $response['errmsg'] ?? 'ok')) ?: 'ok',
                'data' => $data,
            ];
            $this->persistDeliveredCodeResult($order, $normalizedDeliveryCode, $result);

            return $result;
        }

        $payload = [];
        if ($normalizedDeliveryCode !== '') {
            $payload['deliveryCode'] = $normalizedDeliveryCode;
        }

        return $this->deliveredOrder($orderId, $order->getProvider(), $payload)
            ?? $this->buildUnavailableOrderActionResponse('Nao foi possivel concluir a entrega da loja na 99Food.');
    }

    private function persistOrderConfirmResult(Order $order, ?array $response): array
    {
        $safeResponse = is_array($response)
            ? $response
            : $this->buildUnavailableOrderActionResponse('Nao foi possivel confirmar o pedido na 99Food.');

        $this->persistOrderIntegrationState($order, [
            'confirm_at' => date('Y-m-d H:i:s'),
            'confirm_errno' => isset($safeResponse['errno']) ? (string) $safeResponse['errno'] : '',
            'confirm_message' => $safeResponse['errmsg'] ?? '',
        ]);

        if ($this->isSuccessfulErrno($safeResponse['errno'] ?? null)) {
            $this->persistOrderIntegrationState($order, [
                'remote_order_state' => 'preparing',
            ]);
            $this->applyLocalPreparingStatus($order);
            $this->entityManager->flush();
        }

        return $safeResponse;
    }

    private function isSuccessfulErrno(mixed $errno): bool
    {
        if ($errno === null) {
            return false;
        }

        if (is_numeric($errno)) {
            return (int) $errno === 0;
        }

        return trim((string) $errno) === '0';
    }

    private function persistOrderReconcileResult(Order $order, ?array $response, ?int $latencyMs = null): array
    {
        $safeResponse = is_array($response)
            ? $response
            : $this->buildUnavailableOrderActionResponse('Nao foi possivel reconciliar o pedido com a 99Food.');

        $this->persistOrderIntegrationState($order, [
            'reconcile_at' => date('Y-m-d H:i:s'),
            'reconcile_errno' => isset($safeResponse['errno']) ? (string) $safeResponse['errno'] : '',
            'reconcile_message' => $safeResponse['errmsg'] ?? '',
            'reconcile_latency_ms' => $latencyMs !== null ? (string) max(0, $latencyMs) : '',
        ]);

        return $safeResponse;
    }

    private function resolveRemoteOrderId(Order $order): ?string
    {
        $state = $this->getStoredOrderIntegrationState($order);

        return $state['food99_id']
            ?: $state['food99_code'];
    }

    public function reconcileOrder(Order $order): array
    {
        $remoteOrderId = $this->resolveRemoteOrderId($order);
        if (!$remoteOrderId) {
            $result = $this->buildUnavailableOrderActionResponse('Pedido 99Food sem identificador remoto para reconciliacao.');
            $this->persistOrderReconcileResult($order, $result);
            $this->entityManager->flush();

            return $result;
        }

        $startedAt = microtime(true);
        $remoteResponse = $this->getOrderDetails($order->getProvider(), $remoteOrderId);
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $safeResponse = $this->persistOrderReconcileResult($order, $remoteResponse, $latencyMs);

        $isSuccess = $this->isSuccessfulErrno($safeResponse['errno'] ?? null);

        if ($isSuccess) {
            $remoteData = is_array($safeResponse['data'] ?? null) ? $safeResponse['data'] : [];
            if (!isset($remoteData['order_id'])) {
                $remoteData['order_id'] = $remoteOrderId;
            }

            $syncPayload = [
                'type' => 'orderDetailSync',
                'event_time' => date('Y-m-d H:i:s'),
                'data' => $remoteData,
            ];

            $this->handleGenericOrderEvent($syncPayload, 'orderDetailSync', false);
        } else {
            $this->entityManager->flush();
        }

        self::$logger->info('Food99 order reconciliation finished', [
            'local_order_id' => $order->getId(),
            'remote_order_id' => $remoteOrderId,
            'provider_id' => $order->getProvider()?->getId(),
            'reconcile_errno' => $safeResponse['errno'] ?? null,
            'reconcile_message' => $safeResponse['errmsg'] ?? null,
            'latency_ms' => $latencyMs,
            'success' => $isSuccess,
        ]);

        return $safeResponse;
    }

    public function getOrderCancelReasons(Order $order): array
    {
        $state = $this->getStoredOrderIntegrationState($order);

        return [
            'errno' => 0,
            'errmsg' => 'ok',
            'data' => [
                'delivery_type' => $state['delivery_type'] ?? '',
                'delivery_label' => $state['delivery_label'] ?? 'Indefinido',
                'reasons' => $this->buildShopCancelReasonListForState($state),
            ],
        ];
    }

    public function performReadyAction(Order $order): array
    {
        $orderId = $this->resolveRemoteOrderId($order);
        if (!$orderId) {
            return $this->buildUnavailableOrderActionResponse('Pedido 99Food sem identificador remoto.');
        }

        return $this->persistOrderActionResult(
            $order,
            'ready',
            $this->readyOrder($orderId, $order->getProvider())
        );
    }

    public function performCancelAction(Order $order, ?int $reasonId = null, ?string $reason = null): array
    {
        $orderId = $this->resolveRemoteOrderId($order);
        if (!$orderId) {
            return $this->buildUnavailableOrderActionResponse('Pedido 99Food sem identificador remoto.');
        }

        $state = $this->getStoredOrderIntegrationState($order);
        $resolvedReasonId = $reasonId ?: 1080;
        $definition = $this->findShopCancelReasonDefinition($resolvedReasonId);

        if (!$definition) {
            return $this->persistOrderActionResult(
                $order,
                'cancel',
                $this->buildUnavailableOrderActionResponse('Motivo de cancelamento da 99Food invalido.')
            );
        }

        if (!$this->isCancelReasonApplicableToState($definition, $state)) {
            return $this->persistOrderActionResult(
                $order,
                'cancel',
                $this->buildUnavailableOrderActionResponse('O motivo selecionado nao se aplica ao tipo de entrega deste pedido.')
            );
        }

        $resolvedReason = trim((string) $reason);
        if ($resolvedReasonId === 1080 && $resolvedReason === '') {
            $resolvedReason = 'Cancelled by merchant system';
        }

        $result = $this->persistOrderActionResult(
            $order,
            'cancel',
            $this->cancelByShop($orderId, $order->getProvider(), $resolvedReasonId, $resolvedReason)
        );

        if ($this->isSuccessfulErrno($result['errno'] ?? null)) {
            $this->persistOrderIntegrationState($order, [
                'cancel_code' => (string) $resolvedReasonId,
                'cancel_reason' => $resolvedReason !== '' ? $resolvedReason : (string) ($definition['description'] ?? ''),
            ]);
            $this->entityManager->flush();
        }

        return $result;
    }

    public function performVerifyAction(Order $order, array $payload): array
    {
        $orderId = $this->resolveRemoteOrderId($order);
        if (!$orderId) {
            return $this->buildUnavailableOrderActionResponse('Pedido 99Food sem identificador remoto.');
        }

        $offlineGoodsPrice = $payload['offline_goods_price'] ?? $payload['offlineGoodsPrice'] ?? null;
        if (!is_numeric($offlineGoodsPrice)) {
            return $this->persistOrderActionResult(
                $order,
                'verify',
                $this->buildUnavailableOrderActionResponse('offline_goods_price deve ser informado em centavos para validar o pedido.')
            );
        }

        $requestPayload = [
            'order_id' => $orderId,
            'offline_goods_price' => (int) round((float) $offlineGoodsPrice),
        ];

        foreach (['picker_id', 'cashier_id'] as $fieldName) {
            $value = $payload[$fieldName] ?? null;
            if ($value !== null && $value !== '' && is_numeric($value)) {
                $requestPayload[$fieldName] = (int) $value;
            }
        }

        return $this->persistOrderActionResult(
            $order,
            'verify',
            $this->verifyOrder($order->getProvider(), $requestPayload)
        );
    }

    public function performCashPaymentConfirmAction(Order $order): array
    {
        $orderId = $this->resolveRemoteOrderId($order);
        if (!$orderId) {
            return $this->buildUnavailableOrderActionResponse('Pedido 99Food sem identificador remoto.');
        }

        return $this->persistOrderActionResult(
            $order,
            'pay_confirm',
            $this->confirmCashPayment($order->getProvider(), [
                'order_id' => $orderId,
            ])
        );
    }

    public function performDeliveredAction(Order $order, ?string $deliveryCode = null, ?string $locator = null): array
    {
        $orderId = $this->resolveRemoteOrderId($order);
        if (!$orderId) {
            return $this->buildUnavailableOrderActionResponse('Pedido 99Food sem identificador remoto.');
        }

        $state = $this->getStoredOrderIntegrationState($order);
        if (!empty($state['is_platform_delivery'])) {
            return $this->buildUnavailableOrderActionResponse(
                'Pedidos com entrega 99 sao finalizados pela plataforma apos o status pronto.'
            );
        }

        if ($this->requiresStoreDeliveryLocator($state)) {
            $normalizedLocator = $this->normalizeDeliveryLocator($locator ?: (string) ($state['locator'] ?? ''));
            if (strlen($normalizedLocator) !== 8) {
                return $this->persistOrderActionResult(
                    $order,
                    'delivered',
                    $this->buildUnavailableOrderActionResponse(
                        'Informe o localizador de 8 digitos para concluir a entrega 99Food.'
                    )
                );
            }

            $normalizedDeliveryCode = $this->normalizeDeliveryCode($deliveryCode);
            if (strlen($normalizedDeliveryCode) !== 4) {
                return $this->persistOrderActionResult(
                    $order,
                    'delivered',
                    $this->buildUnavailableOrderActionResponse(
                        'Informe o codigo de 4 digitos do cliente para concluir a entrega 99Food.'
                    )
                );
            }

            $this->persistDeliveredCodeResult($order, $normalizedDeliveryCode);

            return $this->persistOrderActionResult(
                $order,
                'delivered',
                $this->completeStoreDeliveryOrder($order, $orderId, $normalizedDeliveryCode, $normalizedLocator)
            );
        }

        return $this->persistOrderActionResult(
            $order,
            'delivered',
            !empty($state['is_store_delivery'])
                ? $this->completeStoreDeliveryOrder($order, $orderId)
                : $this->deliveredOrder($orderId, $order->getProvider())
        );
    }

    private function call99Endpoint(string $uri, array $payload, ?People $provider = null): void
    {
        $url = $this->getFood99BaseUrl() . $uri;
        $accessToken = $this->resolveAccessToken($provider);

        if (!$accessToken) {
            self::$logger->warning('Food99 action skipped because access token is unavailable', [
                'uri' => $uri,
                'payload' => $payload,
                'provider_id' => $provider?->getId(),
                'api_base_url' => $this->getFood99BaseUrl(),
            ]);
            return;
        }

        $payload['auth_token'] = $accessToken;

        try {
            $startedAt = microtime(true);

            self::$logger->info('Food99 ACTION REQUEST', [
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'provider_id' => $provider?->getId(),
                'api_base_url' => $this->getFood99BaseUrl(),
            ]);

            $response = $this->httpClient->request('POST', $url, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            self::$logger->info('Food99 ACTION RESPONSE', [
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'status_code' => $response->getStatusCode(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'response' => $response->toArray(false),
            ]);
        } catch (\Throwable $e) {
            self::$logger->error('Food99 ACTION ERROR', [
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function call99EndpointWithResponse(string $uri, array $payload, ?People $provider = null): ?array
    {
        $url = $this->getFood99BaseUrl() . $uri;
        $accessToken = $this->resolveAccessToken($provider);

        if (!$accessToken) {
            self::$logger->warning('Food99 action skipped because access token is unavailable', [
                'uri' => $uri,
                'payload' => $payload,
                'provider_id' => $provider?->getId(),
                'api_base_url' => $this->getFood99BaseUrl(),
            ]);
            return null;
        }

        $payload['auth_token'] = $accessToken;

        $startedAt = microtime(true);

        try {
            self::$logger->info('Food99 ACTION REQUEST', [
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'provider_id' => $provider?->getId(),
                'api_base_url' => $this->getFood99BaseUrl(),
            ]);

            $response = $this->httpClient->request('POST', $url, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $result = $response->toArray(false);

            self::$logger->info('Food99 ACTION RESPONSE', [
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'status_code' => $response->getStatusCode(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'response' => $result,
            ]);

            return $result;
        } catch (\Throwable $e) {
            self::$logger->error('Food99 ACTION ERROR', [
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function request99WithResponse(string $method, string $uri, array $payload, array $logContext = []): ?array
    {
        $url = $this->getFood99BaseUrl() . $uri;
        $method = strtoupper($method);
        $requestOptions = [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ];

        if ($method === 'GET') {
            $requestOptions['query'] = $payload;
        } else {
            $requestOptions['json'] = $payload;
        }

        try {
            $startedAt = microtime(true);

            self::$logger->info('Food99 ACTION REQUEST', array_merge([
                'method' => $method,
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'api_base_url' => $this->getFood99BaseUrl(),
            ], $logContext));

            $response = $this->httpClient->request($method, $url, $requestOptions);
            $result = $response->toArray(false);

            self::$logger->info('Food99 ACTION RESPONSE', array_merge([
                'method' => $method,
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'status_code' => $response->getStatusCode(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'response' => $result,
            ], $logContext));

            return $result;
        } catch (\Throwable $e) {
            self::$logger->error('Food99 ACTION ERROR', array_merge([
                'method' => $method,
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $e->getMessage(),
            ], $logContext));

            return null;
        }
    }

    private function request99MultipartWithResponse(string $method, string $uri, array $payload, array $logContext = []): ?array
    {
        $url = $this->getFood99BaseUrl() . $uri;
        $method = strtoupper($method);
        $startedAt = microtime(true);

        if ($method !== 'POST') {
            self::$logger->warning('Food99 multipart request only supports POST', array_merge([
                'method' => $method,
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
            ], $logContext));

            return null;
        }

        try {
            $formData = new FormDataPart($payload);
            $headers = $formData->getPreparedHeaders()->toArray();

            self::$logger->info('Food99 ACTION REQUEST', array_merge([
                'method' => $method,
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'content_type' => 'multipart/form-data',
                'api_base_url' => $this->getFood99BaseUrl(),
            ], $logContext));

            $response = $this->httpClient->request($method, $url, [
                'headers' => $headers,
                'body' => $formData->bodyToIterable(),
            ]);
            $result = $response->toArray(false);

            self::$logger->info('Food99 ACTION RESPONSE', array_merge([
                'method' => $method,
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'status_code' => $response->getStatusCode(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'response' => $result,
            ], $logContext));

            return $result;
        } catch (\Throwable $e) {
            self::$logger->error('Food99 ACTION ERROR', array_merge([
                'method' => $method,
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $e->getMessage(),
            ], $logContext));

            return null;
        }
    }

    private function call99StoreEndpointWithResponse(string $method, string $uri, array $payload = [], ?People $provider = null): ?array
    {
        $accessToken = $this->resolveAccessToken($provider);

        if (!$accessToken) {
            self::$logger->warning('Food99 action skipped because access token is unavailable', [
                'method' => strtoupper($method),
                'uri' => $uri,
                'payload' => $payload,
                'provider_id' => $provider?->getId(),
                'api_base_url' => $this->getFood99BaseUrl(),
            ]);
            return null;
        }

        $payload['auth_token'] = $accessToken;

        return $this->request99WithResponse($method, $uri, $payload, [
            'provider_id' => $provider?->getId(),
        ]);
    }

    private function call99StoreMultipartEndpointWithResponse(string $method, string $uri, array $payload = [], ?People $provider = null): ?array
    {
        $accessToken = $this->resolveAccessToken($provider);

        if (!$accessToken) {
            self::$logger->warning('Food99 action skipped because access token is unavailable', [
                'method' => strtoupper($method),
                'uri' => $uri,
                'payload' => $payload,
                'provider_id' => $provider?->getId(),
                'api_base_url' => $this->getFood99BaseUrl(),
            ]);
            return null;
        }

        $payload['auth_token'] = $accessToken;

        return $this->request99MultipartWithResponse($method, $uri, $payload, [
            'provider_id' => $provider?->getId(),
        ]);
    }

    private function call99AppEndpointWithResponse(string $method, string $uri, array $payload = []): ?array
    {
        $appId = $this->resolveAppId();
        $appSecret = $this->resolveAppSecret();

        if (!$appId || !$appSecret) {
            return null;
        }

        $payload['app_id'] = $payload['app_id'] ?? $appId;
        $payload['app_secret'] = $payload['app_secret'] ?? $appSecret;

        return $this->request99WithResponse($method, $uri, $payload);
    }

    public function getIntegratedStoreCode(People $provider): ?string
    {
        $this->init();

        $code = $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'code');

        if ($code === null || $code === '') {
            return null;
        }

        return (string) $code;
    }

    private function normalizeExtraDataValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value === null) {
            return '';
        }

        $normalizedValue = trim((string) $value);

        if ($normalizedValue === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($normalizedValue, 0, 255);
        }

        return substr($normalizedValue, 0, 255);
    }

    private function ensureFood99FieldId(string $fieldName, string $fieldType = 'text'): ?int
    {
        $sql = <<<SQL
            SELECT id
            FROM extra_fields
            WHERE context = :context
              AND field_name = :fieldName
            ORDER BY id ASC
            LIMIT 1
        SQL;

        $connection = $this->entityManager->getConnection();
        $fieldId = $connection->fetchOne($sql, [
            'context' => self::APP_CONTEXT,
            'fieldName' => $fieldName,
        ]);

        if (is_numeric($fieldId)) {
            return (int) $fieldId;
        }

        try {
            $connection->insert('extra_fields', [
                'field_name' => $fieldName,
                'field_type' => $fieldType,
                'context' => self::APP_CONTEXT,
                'required' => 0,
                'field_configs' => '{}',
            ]);
        } catch (\Throwable $e) {
            self::$logger->warning('Food99 extra field creation failed, retrying lookup', [
                'field_name' => $fieldName,
                'error' => $e->getMessage(),
            ]);
        }

        $fieldId = $connection->fetchOne($sql, [
            'context' => self::APP_CONTEXT,
            'fieldName' => $fieldName,
        ]);

        return is_numeric($fieldId) ? (int) $fieldId : null;
    }

    private function getFood99ExtraDataValue(string $entityName, int $entityId, string $fieldName = 'code'): ?string
    {
        if ($entityId <= 0) {
            return null;
        }

        $sql = <<<SQL
            SELECT ed.data_value
            FROM extra_data ed
            INNER JOIN extra_fields ef ON ef.id = ed.extra_fields_id
            WHERE ef.context = :context
              AND ef.field_name = :fieldName
              AND LOWER(ed.entity_name) = LOWER(:entityName)
              AND ed.entity_id = :entityId
            ORDER BY ed.id DESC
            LIMIT 1
        SQL;

        $value = $this->entityManager->getConnection()->fetchOne($sql, [
            'context' => self::APP_CONTEXT,
            'fieldName' => $fieldName,
            'entityName' => $entityName,
            'entityId' => $entityId,
        ]);

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function getFood99ExtraDataValueByEntity(object $entity, string $fieldName = 'code'): ?string
    {
        if (!method_exists($entity, 'getId')) {
            return null;
        }

        $entityId = (int) $entity->getId();
        if ($entityId <= 0) {
            return null;
        }

        $parts = explode('\\', $entity::class);
        $entityName = end($parts) ?: '';

        return $this->getFood99ExtraDataValue($entityName, $entityId, $fieldName);
    }

    private function upsertFood99ExtraDataValue(
        string $entityName,
        int $entityId,
        string $fieldName,
        mixed $value,
        string $fieldType = 'text'
    ): ?string {
        if ($entityId <= 0) {
            return null;
        }

        $fieldId = $this->ensureFood99FieldId($fieldName, $fieldType);
        if (!$fieldId) {
            self::$logger->error('Food99 extra field could not be ensured', [
                'entity_name' => $entityName,
                'entity_id' => $entityId,
                'field_name' => $fieldName,
            ]);
            return null;
        }

        $normalizedValue = $this->normalizeExtraDataValue($value);
        $connection = $this->entityManager->getConnection();
        $existingId = $connection->fetchOne(
            'SELECT id FROM extra_data WHERE extra_fields_id = :fieldId AND LOWER(entity_name) = LOWER(:entityName) AND entity_id = :entityId ORDER BY id DESC LIMIT 1',
            [
                'fieldId' => $fieldId,
                'entityName' => $entityName,
                'entityId' => $entityId,
            ]
        );

        $payload = [
            'data_value' => $normalizedValue,
            'source' => self::APP_CONTEXT,
            'dateTime' => date('Y-m-d H:i:s'),
        ];

        try {
            if (is_numeric($existingId)) {
                $connection->update('extra_data', $payload, [
                    'id' => (int) $existingId,
                ]);
            } else {
                $connection->insert('extra_data', array_merge($payload, [
                    'extra_fields_id' => $fieldId,
                    'entity_id' => $entityId,
                    'entity_name' => $entityName,
                ]));
            }
        } catch (\Throwable $e) {
            self::$logger->error('Food99 extra data upsert failed', [
                'entity_name' => $entityName,
                'entity_id' => $entityId,
                'field_name' => $fieldName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return $normalizedValue === '' ? null : $normalizedValue;
    }

    private function findFood99EntityByExtraData(
        string $entityName,
        string $fieldName,
        mixed $value,
        string $entityClass
    ): ?object {
        $normalizedValue = $this->normalizeExtraDataValue($value);
        if ($normalizedValue === '') {
            return null;
        }

        $sql = <<<SQL
            SELECT ed.entity_id
            FROM extra_data ed
            INNER JOIN extra_fields ef ON ef.id = ed.extra_fields_id
            WHERE ef.context = :context
              AND ef.field_name = :fieldName
              AND LOWER(ed.entity_name) = LOWER(:entityName)
              AND ed.data_value = :value
            ORDER BY ed.id DESC
            LIMIT 1
        SQL;

        $entityId = $this->entityManager->getConnection()->fetchOne($sql, [
            'context' => self::APP_CONTEXT,
            'fieldName' => $fieldName,
            'entityName' => $entityName,
            'value' => $normalizedValue,
        ]);

        if (!is_numeric($entityId)) {
            return null;
        }

        return $this->entityManager->getRepository($entityClass)->find((int) $entityId);
    }

    private function findFood99OrderByLegacyAwareExtraData(string $fieldName, mixed $value): ?Order
    {
        $normalizedValue = $this->normalizeExtraDataValue($value);
        if ($normalizedValue === '') {
            return null;
        }

        $sql = <<<SQL
            SELECT ed.entity_id
            FROM extra_data ed
            INNER JOIN extra_fields ef ON ef.id = ed.extra_fields_id
            INNER JOIN orders o ON o.id = ed.entity_id
            WHERE ef.field_name = :fieldName
              AND LOWER(ed.entity_name) = 'order'
              AND ed.data_value = :value
              AND (ef.context = :primaryContext OR ef.context = :legacyContext)
              AND o.app = :orderApp
            ORDER BY CASE WHEN ef.context = :primaryContext THEN 0 ELSE 1 END, ed.id DESC
            LIMIT 1
        SQL;

        $entityId = $this->entityManager->getConnection()->fetchOne($sql, [
            'fieldName' => $fieldName,
            'value' => $normalizedValue,
            'primaryContext' => self::APP_CONTEXT,
            'legacyContext' => self::LEGACY_ORDER_CONTEXT,
            'orderApp' => self::APP_CONTEXT,
        ]);

        return is_numeric($entityId)
            ? $this->entityManager->getRepository(Order::class)->find((int) $entityId)
            : null;
    }

    private function getFood99OrderExtraDataValue(int $entityId, string $fieldName): ?string
    {
        if ($entityId <= 0) {
            return null;
        }

        $sql = <<<SQL
            SELECT ed.data_value
            FROM extra_data ed
            INNER JOIN extra_fields ef ON ef.id = ed.extra_fields_id
            INNER JOIN orders o ON o.id = ed.entity_id
            WHERE ef.field_name = :fieldName
              AND LOWER(ed.entity_name) = 'order'
              AND ed.entity_id = :entityId
              AND (ef.context = :primaryContext OR ef.context = :legacyContext)
              AND o.app = :orderApp
            ORDER BY CASE WHEN ef.context = :primaryContext THEN 0 ELSE 1 END, ed.id DESC
            LIMIT 1
        SQL;

        $value = $this->entityManager->getConnection()->fetchOne($sql, [
            'fieldName' => $fieldName,
            'entityId' => $entityId,
            'primaryContext' => self::APP_CONTEXT,
            'legacyContext' => self::LEGACY_ORDER_CONTEXT,
            'orderApp' => self::APP_CONTEXT,
        ]);

        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function decodeOrderOtherInformationsValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (is_array($decoded)) {
                return $decoded;
            }

            if (is_string($decoded)) {
                $decodedAgain = json_decode($decoded, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedAgain)) {
                    return $decodedAgain;
                }
            }
        }

        return [];
    }

    private function getDecodedOrderOtherInformations(Order $order): array
    {
        $otherInformations = [];

        try {
            $otherInformations = $this->decodeOrderOtherInformationsValue($order->getOtherInformations(true));
        } catch (\Throwable) {
            $otherInformations = [];
        }

        return $otherInformations;
    }

    private function extractOrderIntegrationStateFromOtherInformations(Order $order): array
    {
        $otherInformations = $this->getDecodedOrderOtherInformations($order);
        $payload = null;

        foreach ([self::APP_CONTEXT, self::LEGACY_ORDER_CONTEXT] as $contextKey) {
            $candidate = $otherInformations[$contextKey] ?? null;
            if (is_array($candidate)) {
                $payload = $candidate;
                break;
            }

            if (is_string($candidate)) {
                $decodedCandidate = $this->decodeOrderOtherInformationsValue($candidate);
                if (!empty($decodedCandidate)) {
                    $payload = $decodedCandidate;
                    break;
                }
            }
        }

        if (!is_array($payload)) {
            return [];
        }

        $payload = $this->unwrapStoredOrderPayload($payload);
        $identifiers = $this->extractIncomingOrderIdentifiers($payload);
        $orderId = $identifiers['order_id'] ?? '';
        $orderIndex = $identifiers['order_index'] ?? '';

        if ($orderId === '') {
            $orderId = $this->normalizeIncomingFood99Value(
                $this->searchPayloadValueByKeys($payload, ['order_id', 'orderId'])
            );
        }

        if ($orderIndex === '') {
            $orderIndex = $this->normalizeIncomingFood99Value(
                $this->searchPayloadValueByKeys($payload, ['order_index', 'orderIndex'])
            );
        }

        $deliveryStatus = $this->extractOrderDeliveryStatus($payload);
        $eventAt = $this->extractOrderEventTimestamp($payload);
        $state = [
            'food99_id' => $orderId !== '' ? $orderId : null,
            'food99_code' => $this->resolveIncomingOrderCode($orderId, $orderIndex),
            'remote_order_state' => $this->normalizeIncomingFood99Value($payload['type'] ?? null) === 'orderNew' ? 'new' : null,
            'remote_delivery_status' => $deliveryStatus !== '' ? $deliveryStatus : null,
            'last_event_type' => $this->normalizeIncomingFood99Value($payload['type'] ?? null),
            'last_event_at' => $eventAt !== '' ? $eventAt : null,
            'cancel_code' => null,
            'cancel_reason' => null,
            'last_action' => null,
            'last_action_at' => null,
            'last_action_errno' => null,
            'last_action_message' => null,
            'confirm_at' => null,
            'confirm_errno' => null,
            'confirm_message' => null,
            'reconcile_at' => null,
            'reconcile_errno' => null,
            'reconcile_message' => null,
            'reconcile_latency_ms' => null,
        ];

        $state = array_merge($state, $this->extractOrderDeliveryStateFields($payload));
        $state = array_merge($state, $this->resolveOrderDeliveryFlags($state));
        $state['allows_manual_delivery_completion'] = $this->resolveAllowsManualDeliveryCompletion($state);

        return $state;
    }

    private function unwrapStoredOrderPayload(array $payload): array
    {
        $normalizedPayload = $payload;

        for ($depth = 0; $depth < 5; $depth++) {
            $candidate = $normalizedPayload[self::APP_CONTEXT] ?? null;
            if (!is_array($candidate)) {
                break;
            }

            $normalizedPayload = $candidate;
        }

        return $normalizedPayload;
    }

    private function findLocalFoodCodeByEntity(string $entityName, int $entityId): ?string
    {
        return $this->getFood99ExtraDataValue($entityName, $entityId, 'code');
    }

    private function findLocalFoodIdByEntity(string $entityName, int $entityId): ?string
    {
        return $this->getFood99ExtraDataValue($entityName, $entityId, 'id');
    }

    private function persistLocalFoodCodeByEntity(string $entityName, int $entityId, string $code): ?string
    {
        return $this->upsertFood99ExtraDataValue($entityName, $entityId, 'code', $code);
    }

    private function persistLocalFoodIdByEntity(string $entityName, int $entityId, string $id): ?string
    {
        return $this->upsertFood99ExtraDataValue($entityName, $entityId, 'id', $id);
    }

    private function findExistingIntegratedOrder(
        string $orderId,
        string $orderCode,
        bool $allowCodeFallback = true
    ): ?Order
    {
        if ($orderId !== '') {
            $order = $this->findFood99OrderByLegacyAwareExtraData('id', $orderId);
            if ($order instanceof Order) {
                return $order;
            }

            if (!$allowCodeFallback) {
                return null;
            }
        }

        if ($orderCode !== '') {
            $order = $this->findFood99OrderByLegacyAwareExtraData('code', $orderCode);
            if ($order instanceof Order) {
                return $order;
            }
        }

        return null;
    }

    private function resolveIncomingOrderCode(string $orderId, string $orderIndex): string
    {
        return $orderIndex !== '' ? $orderIndex : $orderId;
    }

    private function extractIncomingOrderIdentifiers(array $json): array
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $info = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];

        $orderId = $this->normalizeIncomingFood99Value(
            $data['order_id']
                ?? $data['orderId']
                ?? $info['order_id']
                ?? $info['orderId']
                ?? null
        );

        $orderIndex = $this->normalizeIncomingFood99Value(
            $info['order_index']
                ?? $info['orderIndex']
                ?? $data['order_index']
                ?? $data['orderIndex']
                ?? null
        );

        return [
            'order_id' => $orderId,
            'order_index' => $orderIndex,
            'order_code' => $this->resolveIncomingOrderCode($orderId, $orderIndex),
        ];
    }

    private function extractWebhookMeta(array $json): array
    {
        $meta = is_array($json['__webhook'] ?? null) ? $json['__webhook'] : [];
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $info = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];
        $shop = is_array($info['shop'] ?? null)
            ? $info['shop']
            : (is_array($data['shop'] ?? null) ? $data['shop'] : []);

        $eventId = $this->normalizeIncomingFood99Value(
            $meta['event_id']
                ?? $json['event_id']
                ?? $json['eventId']
                ?? $json['id']
                ?? $json['requestId']
                ?? null
        );
        $eventType = $this->normalizeIncomingFood99Value($meta['event_type'] ?? $json['type'] ?? null);
        $orderIdentifiers = $this->extractIncomingOrderIdentifiers($json);
        $orderId = $orderIdentifiers['order_id'];
        $shopId = $this->normalizeIncomingFood99Value(
            $meta['shop_id']
                ?? $shop['shop_id']
                ?? $data['shop_id']
                ?? $json['app_shop_id']
                ?? null
        );
        $receivedAt = $this->normalizeIncomingFood99Value($meta['received_at'] ?? null);
        if ($receivedAt === '') {
            $receivedAt = date('Y-m-d H:i:s');
        }

        return [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'event_at' => $this->extractOrderEventTimestamp($json),
            'received_at' => $receivedAt,
            'order_id' => $orderId,
            'shop_id' => $shopId,
        ];
    }

    private function resolveProviderFromWebhookPayload(array $json): ?People
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $info = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];
        $shop = is_array($info['shop'] ?? null)
            ? $info['shop']
            : (is_array($data['shop'] ?? null) ? $data['shop'] : []);

        $candidateShopIds = array_values(array_unique(array_filter([
            $this->normalizeIncomingFood99Value($shop['shop_id'] ?? null),
            $this->normalizeIncomingFood99Value($data['shop_id'] ?? null),
            $this->normalizeIncomingFood99Value($json['app_shop_id'] ?? null),
            $this->normalizeIncomingFood99Value($this->extractWebhookMeta($json)['shop_id'] ?? null),
        ], static fn(string $value): bool => $value !== '')));

        foreach ($candidateShopIds as $candidateShopId) {
            $provider = $this->findFood99EntityByExtraData('People', 'code', $candidateShopId, People::class);
            if ($provider instanceof People) {
                return $provider;
            }

            if (ctype_digit($candidateShopId)) {
                $provider = $this->entityManager->getRepository(People::class)->find((int) $candidateShopId);
                if ($provider instanceof People) {
                    return $provider;
                }
            }
        }

        return null;
    }

    private function syncProviderWebhookReceiptState(array $json): void
    {
        $provider = $this->resolveProviderFromWebhookPayload($json);
        if (!$provider instanceof People) {
            return;
        }

        $meta = $this->extractWebhookMeta($json);
        $fields = [
            'last_webhook_event_type' => $meta['event_type'],
            'last_webhook_event_at' => $meta['event_at'],
            'last_webhook_received_at' => $meta['received_at'],
            'last_webhook_processed_at' => date('Y-m-d H:i:s'),
        ];

        if ($meta['event_id'] !== '') {
            $fields['last_webhook_event_id'] = $meta['event_id'];
        }
        if ($meta['order_id'] !== '') {
            $fields['last_webhook_order_id'] = $meta['order_id'];
        }
        if ($meta['shop_id'] !== '') {
            $fields['last_webhook_shop_id'] = $meta['shop_id'];
        }

        $this->persistProviderIntegrationState($provider, $fields);
    }

    private function waitForExistingIntegratedOrder(
        string $orderId,
        string $orderCode,
        int $attempts = 5,
        int $sleepMicroseconds = 250000,
        bool $allowCodeFallback = true
    ): ?Order
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $existing = $this->findExistingIntegratedOrder($orderId, $orderCode, $allowCodeFallback);
            if ($existing instanceof Order) {
                return $existing;
            }

            usleep($sleepMicroseconds);
        }

        return null;
    }

    private function resolveOrderClient(People $provider, array $address, string $orderId): People
    {
        $client = $this->discoveryClient($address, $provider);
        if ($client instanceof People) {
            $this->peopleService->discoveryLink($provider, $client, 'client');
            return $client;
        }

        $fallbackName = $this->resolveFood99CustomerName($address);
        $clientCode = $this->resolveFood99RemoteClientId($address);
        $phone = $this->resolveFood99ClientPhone($address);

        self::$logger->warning('Food99 order received without a mapped customer; using fallback customer resolution', [
            'order_id' => $orderId,
            'client_code' => $clientCode,
            'address_keys' => array_keys($address),
        ]);

        $client = $this->peopleService->discoveryPeople(
            null,
            null,
            $phone,
            $fallbackName,
            'F'
        );

        $this->syncFood99ClientData($client, $provider, $address, $clientCode);

        return $client;
    }

    private function persistOrderIntegrationState(Order $order, array $fields): void
    {
        foreach ($fields as $fieldName => $value) {
            $this->upsertFood99ExtraDataValue('Order', (int) $order->getId(), (string) $fieldName, $value);
        }
    }

    public function getStoredOrderIntegrationState(Order $order): array
    {
        $this->init();

        $orderId = (int) $order->getId();
        $state = [
            'food99_id' => $this->getFood99OrderExtraDataValue($orderId, 'id'),
            'food99_code' => $this->getFood99OrderExtraDataValue($orderId, 'code'),
            'remote_order_state' => $this->getFood99OrderExtraDataValue($orderId, 'remote_order_state'),
            'remote_delivery_status' => $this->getFood99OrderExtraDataValue($orderId, 'remote_delivery_status'),
            'last_event_type' => $this->getFood99OrderExtraDataValue($orderId, 'last_event_type'),
            'last_event_at' => $this->getFood99OrderExtraDataValue($orderId, 'last_event_at'),
            'cancel_code' => $this->getFood99OrderExtraDataValue($orderId, 'cancel_code'),
            'cancel_reason' => $this->getFood99OrderExtraDataValue($orderId, 'cancel_reason'),
            'last_action' => $this->getFood99OrderExtraDataValue($orderId, 'last_action'),
            'last_action_at' => $this->getFood99OrderExtraDataValue($orderId, 'last_action_at'),
            'last_action_errno' => $this->getFood99OrderExtraDataValue($orderId, 'last_action_errno'),
            'last_action_message' => $this->getFood99OrderExtraDataValue($orderId, 'last_action_message'),
            'confirm_at' => $this->getFood99OrderExtraDataValue($orderId, 'confirm_at'),
            'confirm_errno' => $this->getFood99OrderExtraDataValue($orderId, 'confirm_errno'),
            'confirm_message' => $this->getFood99OrderExtraDataValue($orderId, 'confirm_message'),
            'reconcile_at' => $this->getFood99OrderExtraDataValue($orderId, 'reconcile_at'),
            'reconcile_errno' => $this->getFood99OrderExtraDataValue($orderId, 'reconcile_errno'),
            'reconcile_message' => $this->getFood99OrderExtraDataValue($orderId, 'reconcile_message'),
            'reconcile_latency_ms' => $this->getFood99OrderExtraDataValue($orderId, 'reconcile_latency_ms'),
            'delivery_type' => $this->getFood99OrderExtraDataValue($orderId, 'delivery_type'),
            'fulfillment_mode' => $this->getFood99OrderExtraDataValue($orderId, 'fulfillment_mode'),
            'expected_arrived_eta' => $this->getFood99OrderExtraDataValue($orderId, 'expected_arrived_eta'),
            'pickup_code' => $this->getFood99OrderExtraDataValue($orderId, 'pickup_code'),
            'locator' => $this->getFood99OrderExtraDataValue($orderId, 'locator'),
            'handover_page_url' => $this->getFood99OrderExtraDataValue($orderId, 'handover_page_url'),
            'virtual_phone_number' => $this->getFood99OrderExtraDataValue($orderId, 'virtual_phone_number'),
            'handover_code' => $this->getFood99OrderExtraDataValue($orderId, 'handover_code'),
            'rider_name' => $this->getFood99OrderExtraDataValue($orderId, 'rider_name'),
            'rider_phone' => $this->getFood99OrderExtraDataValue($orderId, 'rider_phone'),
            'rider_to_store_eta' => $this->getFood99OrderExtraDataValue($orderId, 'rider_to_store_eta'),
        ];

        $fallbackState = $this->extractOrderIntegrationStateFromOtherInformations($order);

        foreach ($state as $key => $value) {
            if ($value !== null && $value !== '') {
                continue;
            }

            $state[$key] = $fallbackState[$key] ?? $value;
        }

        $state = array_merge($state, $this->resolveOrderDeliveryFlags($state));
        $state['allows_manual_delivery_completion'] = $this->resolveAllowsManualDeliveryCompletion($state);

        return $state;
    }

    private function resolveBestStoredOrderPayload(Order $order): array
    {
        $otherInformations = $this->getDecodedOrderOtherInformations($order);
        $candidateKeys = [];
        $latestEventType = $this->normalizeIncomingFood99Value($otherInformations['latest_event_type'] ?? null);

        if ($latestEventType !== '') {
            $candidateKeys[] = $latestEventType;
        }

        $candidateKeys = array_merge($candidateKeys, [
            'orderDetailSync',
            'orderNew',
            self::APP_CONTEXT,
            self::LEGACY_ORDER_CONTEXT,
        ]);

        foreach (array_unique($candidateKeys) as $candidateKey) {
            $candidate = $otherInformations[$candidateKey] ?? null;
            if (is_string($candidate)) {
                $candidate = $this->decodeOrderOtherInformationsValue($candidate);
            }

            if (!is_array($candidate) || empty($candidate)) {
                continue;
            }

            $payload = $this->unwrapStoredOrderPayload($candidate);
            if (!is_array($payload) || empty($payload)) {
                continue;
            }

            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $orderInfo = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];
            if (!empty($orderInfo) || !empty($data)) {
                return $payload;
            }
        }

        return [];
    }

    private function normalizeFood99Money(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return round(((float) $value) / 100, 2);
    }

    private function normalizeFood99Boolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, ['true', 'yes', 'y', 'sim'], true)) {
            return true;
        }

        if (in_array($normalized, ['false', 'no', 'n', 'nao'], true)) {
            return false;
        }

        return null;
    }

    private function sumPromotionStoreSubsidy(array $promotions): float
    {
        $total = 0.0;

        foreach ($promotions as $promotion) {
            if (!is_array($promotion)) {
                continue;
            }

            $total += $this->normalizeFood99Money($promotion['shop_subside_price'] ?? null);
        }

        return round($total, 2);
    }

    private function sumPromotionTotalDiscount(array $promotions): float
    {
        $total = 0.0;

        foreach ($promotions as $promotion) {
            if (!is_array($promotion)) {
                continue;
            }

            $total += $this->normalizeFood99Money($promotion['promo_discount'] ?? null);
        }

        return round($total, 2);
    }

    private function extractOrderPromotionList(array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $orderInfo = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];
        $promotions = $orderInfo['promotions'] ?? $data['promotions'] ?? [];
        if (is_array($promotions) && !empty($promotions)) {
            return array_values(array_filter($promotions, 'is_array'));
        }

        $items = $orderInfo['order_items'] ?? $data['order_items'] ?? [];
        $fallback = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $promotionDetail = is_array($item['promotion_detail'] ?? null) ? $item['promotion_detail'] : null;
            if ($promotionDetail === null) {
                continue;
            }

            $fallback[] = $promotionDetail;
        }

        return $fallback;
    }

    private function buildFood99AddressDisplay(array $address): ?string
    {
        $parts = array_filter([
            $this->normalizeIncomingFood99Value($address['poi_address'] ?? null),
            $this->normalizeIncomingFood99Value($address['street_name'] ?? null),
            $this->normalizeIncomingFood99Value($address['street_number'] ?? null),
            $this->normalizeIncomingFood99Value($address['district'] ?? null),
            $this->normalizeIncomingFood99Value($address['city'] ?? null),
            $this->normalizeIncomingFood99Value($address['state'] ?? null),
            $this->normalizeIncomingFood99Value($address['postal_code'] ?? null),
            $this->normalizeIncomingFood99Value($address['reference'] ?? null),
        ], static fn($value) => $value !== null && $value !== '');

        if (empty($parts)) {
            return null;
        }

        return implode(', ', array_values(array_unique($parts)));
    }

    private function isFood99PrivacyPlaceholder(mixed $value): bool
    {
        $normalized = strtolower(trim((string) $this->normalizeIncomingFood99Value($value)));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, [
            'privacy protection',
            'privacy_protection',
            'privacy-protection',
            'protected',
        ], true);
    }

    private function sanitizeFood99IdentityValue(mixed $value): ?string
    {
        $normalized = $this->normalizeIncomingFood99Value($value);
        if ($normalized === '' || $this->isFood99PrivacyPlaceholder($normalized)) {
            return null;
        }

        return $normalized;
    }

    private function resolveFood99CustomerName(array $address, string $fallback = 'Cliente Food99'): string
    {
        $nameParts = array_filter([
            $this->sanitizeFood99IdentityValue($address['name'] ?? null),
            $this->sanitizeFood99IdentityValue($address['first_name'] ?? null),
            $this->sanitizeFood99IdentityValue($address['last_name'] ?? null),
        ], static fn($value) => $value !== null && $value !== '');

        $resolved = trim(implode(' ', array_values(array_unique($nameParts))));

        if ($resolved !== '') {
            return $resolved;
        }

        return $fallback;
    }

    private function resolveFood99RemoteClientId(array $address): string
    {
        $clientId = $this->normalizeIncomingFood99Value($address['uid'] ?? null);
        if ($clientId === '0') {
            return '';
        }

        return $clientId;
    }

    private function resolveFood99ClientPhone(array $address): array
    {
        $rawPhone = $this->sanitizeFood99IdentityValue($address['phone'] ?? null);
        if ($rawPhone === null) {
            return [];
        }

        $digits = preg_replace('/\D+/', '', $rawPhone);
        if ($digits === null || $digits === '') {
            return [];
        }

        if (str_starts_with($digits, '55') && strlen($digits) > 11) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) > 11) {
            $digits = substr($digits, -11);
        }

        if (strlen($digits) < 10) {
            return [];
        }

        $ddd = substr($digits, 0, 2);
        $phone = substr($digits, 2);

        if ($ddd === '' || $phone === '') {
            return [];
        }

        return [
            'ddi' => 55,
            'ddd' => (int) $ddd,
            'phone' => (int) $phone,
        ];
    }

    private function shouldUpdateFood99ClientName(People $client, string $resolvedName): bool
    {
        $candidateName = trim($resolvedName);
        if ($candidateName === '') {
            return false;
        }

        $currentName = strtolower(trim((string) $client->getName()));
        $normalizedCandidateName = strtolower($candidateName);

        if ($currentName === $normalizedCandidateName) {
            return false;
        }

        return $currentName === ''
            || $currentName === 'name not given'
            || $currentName === 'cliente food99'
            || str_starts_with($currentName, 'cliente food99 ');
    }

    private function syncFood99ClientData(
        People $client,
        People $provider,
        array $address,
        string $remoteClientId = ''
    ): People {
        $resolvedName = $this->resolveFood99CustomerName($address, '');
        if ($this->shouldUpdateFood99ClientName($client, $resolvedName)) {
            $client->setName($resolvedName);
            $this->entityManager->persist($client);
        }

        $phone = $this->resolveFood99ClientPhone($address);
        if (!empty($phone)) {
            try {
                $this->peopleService->addPhone($client, $phone);
            } catch (\Throwable $exception) {
                self::$logger->warning('Food99 client phone could not be synced', [
                    'client_id' => $client->getId(),
                    'provider_id' => $provider->getId(),
                    'phone' => $phone,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($remoteClientId !== '') {
            $this->extraDataService->discoveryExtraData($client, self::APP_CONTEXT, 'code', $remoteClientId);
        }

        $this->peopleService->discoveryLink($provider, $client, 'client');

        return $client;
    }

    private function resolveFood99PaymentTypeLabel(?string $payType, ?string $deliveryType): string
    {
        $normalizedPayType = trim((string) $payType);

        return match ($normalizedPayType) {
            '1' => 'Pagamento online',
            '2' => 'Dinheiro',
            '3' => 'POS',
            '4' => 'Carteira / 99Pay',
            '5' => 'PayPay sem senha',
            '6' => 'PayPay com senha',
            default => trim((string) $deliveryType) === '1'
                ? 'Pagamento processado pela 99Food'
                : 'Pagamento nao mapeado',
        };
    }

    private function resolveFood99PaymentMethodLabel(?string $payMethod): string
    {
        return match (trim((string) $payMethod)) {
            '1' => 'Pagamento online',
            '2' => 'Pagamento offline',
            '0' => 'Nao informado pela 99',
            default => 'Metodo nao mapeado',
        };
    }

    private function resolveFood99PaymentChannelLabel(?string $payChannel, ?string $payMethod, ?string $deliveryType): string
    {
        $normalizedPayChannel = trim((string) $payChannel);
        $normalizedPayMethod = trim((string) $payMethod);
        $normalizedDeliveryType = trim((string) $deliveryType);

        if ($normalizedPayChannel === '') {
            return '';
        }

        return match ($normalizedPayChannel) {
            '0' => 'Nao informado pela 99',
            '110' => 'Cupom',
            '120' => '99Food Wallet',
            '150' => 'Cartao de credito / debito',
            '153' => 'Dinheiro',
            '154' => 'POS',
            '167' => 'Preauth',
            '182' => 'PayPay sem senha',
            '184' => 'PayPay com senha',
            '190' => '99Pay',
            '212' => 'PIX',
            '219' => '99Food Cuenta',
            '229' => 'NuPay',
            '234' => 'Apple Pay (pre-auth)',
            '235' => 'Apple Pay',
            '257' => 'Vale Refeicao Pluxee',
            '258' => 'Vale Refeicao Ticket',
            '259' => 'Vale Refeicao VR',
            '260' => 'Vale Refeicao Alelo',
            '261' => 'NEQUI',
            '262' => 'POS cartao de credito',
            '263' => 'POS cartao de debito',
            '264' => 'POS vale refeicao',
            '272' => 'Google Pay',
            '273' => 'Google Pay (pre-auth)',
            '310' => 'Yape',
            '311' => 'Plin',
            '901' => 'Beneficio',
            '2008' => 'Marketing',
            default => match ($normalizedPayMethod) {
                '1' => $normalizedDeliveryType === '1'
                    ? 'Pagamento online'
                    : 'Pagamento online selecionado pelo cliente',
                '2' => 'Pagamento offline',
                default => 'Canal nao mapeado',
            },
        };
    }

    private function resolveFood99SelectedPaymentLabel(
        string $paymentChannelLabel,
        string $paymentTypeLabel,
        string $paymentMethodLabel
    ): string {
        $preferredLabels = [
            $paymentChannelLabel,
            $paymentTypeLabel,
            $paymentMethodLabel,
        ];

        foreach ($preferredLabels as $label) {
            $normalizedLabel = trim((string) $label);
            if ($normalizedLabel === '') {
                continue;
            }

            if (in_array($normalizedLabel, [
                'Nao informado pela 99',
                'Canal nao mapeado',
                'Metodo nao mapeado',
                'Pagamento nao mapeado',
            ], true)) {
                continue;
            }

            return $normalizedLabel;
        }

        return trim((string) ($paymentChannelLabel ?: $paymentTypeLabel ?: $paymentMethodLabel));
    }

    public function getOrderHomologationSnapshot(Order $order): array
    {
        $this->init();

        $payload = $this->resolveBestStoredOrderPayload($order);
        if (empty($payload)) {
            return [
                'financial' => null,
                'payment' => null,
                'customer' => null,
                'address' => null,
                'notes' => null,
                'identifiers' => null,
                'raw_payload_available' => false,
            ];
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $orderInfo = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];
        $price = is_array($orderInfo['price'] ?? null) ? $orderInfo['price'] : (is_array($data['price'] ?? null) ? $data['price'] : []);
        $otherFees = is_array($price['others_fees'] ?? null) ? $price['others_fees'] : [];
        $promotions = $this->extractOrderPromotionList($payload);
        $receiveAddress = is_array($data['receive_address'] ?? null) ? $data['receive_address'] : [];
        $deliveryType = $this->normalizeIncomingFood99Value($orderInfo['delivery_type'] ?? $data['delivery_type'] ?? null);
        $payType = $this->normalizeIncomingFood99Value($orderInfo['pay_type'] ?? $data['pay_type'] ?? null);
        $payMethod = $this->normalizeIncomingFood99Value($orderInfo['pay_method'] ?? $data['pay_method'] ?? null);
        $payChannel = $this->normalizeIncomingFood99Value($orderInfo['pay_channel'] ?? $data['pay_channel'] ?? null);
        $storeDiscountTotal = $this->sumPromotionStoreSubsidy($promotions);
        $itemsDiscountTotal = $this->normalizeFood99Money($price['items_discount'] ?? null);
        $deliveryDiscountTotal = $this->normalizeFood99Money($price['delivery_discount'] ?? null);
        $couponDiscountTotal = $this->normalizeFood99Money($otherFees['coupon_discount'] ?? null);
        $promotionsTotal = $this->sumPromotionTotalDiscount($promotions);
        $originalDeliveryFee = $this->normalizeFood99Money($price['store_charged_delivery_price'] ?? $price['delivery_price'] ?? null);
        $changeFor = $this->normalizeFood99Money($orderInfo['change_for'] ?? $data['change_for'] ?? null);
        $shopPaidMoney = $this->normalizeFood99Money($price['shop_paid_money'] ?? null);

        $itemsTotal = $this->normalizeFood99Money($price['order_price'] ?? null);
        $deliveryFee = $originalDeliveryFee;
        $serviceFee = $this->normalizeFood99Money($otherFees['service_price'] ?? null);
        $smallOrderFee = $this->normalizeFood99Money($otherFees['small_order_price'] ?? null);
        $tipTotal = $this->normalizeFood99Money($otherFees['total_tip_money'] ?? null);
        $mealTopUpFee = $this->normalizeFood99Money($otherFees['meal_top_up_price'] ?? null);
        $subtotalBeforeDiscounts = round($itemsTotal + $deliveryFee + $serviceFee + $smallOrderFee + $tipTotal + $mealTopUpFee, 2);
        $explicitCustomerTotal = $this->normalizeFood99Money(
            $price['customer_need_paying_money'] ?? $price['real_pay_price'] ?? $price['real_price'] ?? null
        );
        $knownDiscountTotal = max(
            $itemsDiscountTotal + $deliveryDiscountTotal + $couponDiscountTotal,
            $promotionsTotal
        );
        $customerTotal = $explicitCustomerTotal > 0
            ? $explicitCustomerTotal
            : round(max(0, $subtotalBeforeDiscounts - $knownDiscountTotal), 2);
        $discountTotal = round(max(0, $subtotalBeforeDiscounts - $customerTotal), 2);
        $platformDiscountTotal = round(max(0, $discountTotal - $storeDiscountTotal), 2);
        $paymentTypeLabel = $this->resolveFood99PaymentTypeLabel($payType, $deliveryType);
        $paymentMethodLabel = $this->resolveFood99PaymentMethodLabel($payMethod);
        $paymentChannelLabel = $this->resolveFood99PaymentChannelLabel($payChannel, $payMethod, $deliveryType);
        $selectedPaymentLabel = $this->resolveFood99SelectedPaymentLabel(
            $paymentChannelLabel,
            $paymentTypeLabel,
            $paymentMethodLabel
        );
        $isPlatformDelivery = $deliveryType === '1';
        // 99Food only guarantees "already paid" when delivery is handled by the 99 platform.
        $isPaidOnline = $isPlatformDelivery;
        $amountPaid = $isPaidOnline ? $customerTotal : 0.0;
        $amountPending = round(max(0, $customerTotal - $amountPaid), 2);
        $customerNeedPayingMoney = $this->normalizeFood99Money($price['customer_need_paying_money'] ?? null);
        $changeAmount = $changeFor > 0 && $customerNeedPayingMoney > 0
            ? round(max(0, $changeFor - $customerNeedPayingMoney), 2)
            : 0.0;

        return [
            'financial' => [
                'currency' => 'BRL',
                'items_total' => $itemsTotal,
                'delivery_fee' => $deliveryFee,
                'service_fee' => $serviceFee,
                'small_order_fee' => $smallOrderFee,
                'meal_top_up_fee' => $mealTopUpFee,
                'tip_total' => $tipTotal,
                'subtotal_before_discounts' => $subtotalBeforeDiscounts,
                'discount_total' => $discountTotal,
                'store_discount_total' => $storeDiscountTotal,
                'platform_discount_total' => $platformDiscountTotal,
                'promotions_total' => $promotionsTotal,
                'items_discount_total' => $itemsDiscountTotal,
                'delivery_discount_total' => $deliveryDiscountTotal,
                'coupon_discount_total' => $couponDiscountTotal,
                'customer_total' => $customerTotal,
                'customer_need_paying_money' => $customerNeedPayingMoney,
                'store_receivable_total' => $this->normalizeFood99Money($price['real_price'] ?? null),
                'real_pay_total' => $this->normalizeFood99Money($price['real_pay_price'] ?? null),
                'refund_total' => $this->normalizeFood99Money($price['refund_price'] ?? null),
                'store_charged_delivery_price' => $originalDeliveryFee,
                'shop_paid_money' => $shopPaidMoney,
            ],
            'payment' => [
                'pay_type' => $payType,
                'pay_type_label' => $paymentTypeLabel,
                'pay_method' => $payMethod,
                'pay_method_label' => $paymentMethodLabel,
                'pay_channel' => $payChannel,
                'pay_channel_label' => $paymentChannelLabel,
                'selected_payment_label' => $selectedPaymentLabel,
                'amount_paid' => $amountPaid,
                'amount_pending' => $amountPending,
                'customer_need_paying_money' => $customerNeedPayingMoney,
                'collect_on_delivery_amount' => $isPaidOnline
                    ? 0.0
                    : ($customerNeedPayingMoney ?: $customerTotal),
                'shop_paid_money' => $shopPaidMoney,
                'change_for' => $changeFor,
                'change_amount' => $changeAmount,
                'needs_change' => $changeAmount > 0.009,
                'is_fully_paid' => $amountPending <= 0.009,
                'should_confirm_payment' => !$isPaidOnline,
                'is_paid_online' => $isPaidOnline,
                'delivery_99_always_paid_rule' => $isPlatformDelivery,
            ],
            'customer' => [
                'name' => $this->resolveFood99CustomerName($receiveAddress, ''),
                'phone' => $this->normalizeIncomingFood99Value($receiveAddress['phone'] ?? null),
            ],
            'address' => [
                'display' => $this->buildFood99AddressDisplay($receiveAddress),
                'street_name' => $this->normalizeIncomingFood99Value($receiveAddress['street_name'] ?? null),
                'street_number' => $this->normalizeIncomingFood99Value($receiveAddress['street_number'] ?? null),
                'district' => $this->normalizeIncomingFood99Value($receiveAddress['district'] ?? null),
                'city' => $this->normalizeIncomingFood99Value($receiveAddress['city'] ?? null),
                'state' => $this->normalizeIncomingFood99Value($receiveAddress['state'] ?? null),
                'postal_code' => $this->normalizeIncomingFood99Value($receiveAddress['postal_code'] ?? null),
                'reference' => $this->normalizeIncomingFood99Value($receiveAddress['reference'] ?? null),
                'complement' => $this->normalizeIncomingFood99Value($receiveAddress['complement'] ?? null),
                'poi_address' => $this->normalizeIncomingFood99Value($receiveAddress['poi_address'] ?? null),
            ],
            'notes' => [
                'remark' => $this->normalizeIncomingFood99Value($orderInfo['remark'] ?? $data['remark'] ?? null),
                'need_cutlery' => $this->normalizeFood99Boolean($orderInfo['need_cutlery'] ?? $data['need_cutlery'] ?? null),
            ],
            'identifiers' => [
                'remote_order_id' => $this->normalizeIncomingFood99Value($orderInfo['order_id'] ?? $data['order_id'] ?? null),
                'order_index' => $this->normalizeIncomingFood99Value($orderInfo['order_index'] ?? $data['order_index'] ?? null),
                'delivery_type' => $deliveryType,
                'pickup_code' => $this->normalizeIncomingFood99Value($data['pickup_code'] ?? $orderInfo['pickup_code'] ?? null),
                'handover_code' => $this->normalizeIncomingFood99Value($data['handover_code'] ?? $orderInfo['handover_code'] ?? null),
            ],
            'raw_payload_available' => true,
        ];
    }

    private function extractOrderRemark(array $json): string
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $orderInfo = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];

        return $this->normalizeMarketplaceFreeText(
            $orderInfo['remark']
                ?? $data['remark']
                ?? null
        );
    }

    private function extractItemRemark(array $item): string
    {
        return $this->normalizeMarketplaceFreeText(
            $item['remark']
                ?? $item['remarks']
                ?? $item['comment']
                ?? $item['comments']
                ?? $item['observation']
                ?? $item['observations']
                ?? $item['note']
                ?? $item['notes']
                ?? null
        );
    }

    private function extractOrderDeliveryStateFields(array $json): array
    {
        return [
            'delivery_type' => $this->extractOrderDeliveryType($json),
            'fulfillment_mode' => $this->extractOrderFulfillmentMode($json),
            'expected_arrived_eta' => $this->extractOrderExpectedArrivedEta($json),
            'pickup_code' => $this->extractOrderPickupCode($json),
            'locator' => $this->extractOrderLocator($json),
            'handover_page_url' => $this->extractOrderHandoverPageUrl($json),
            'virtual_phone_number' => $this->extractOrderVirtualPhoneNumber($json),
            'handover_code' => $this->extractOrderHandoverCode($json),
            'rider_name' => $this->extractOrderRiderName($json),
            'rider_phone' => $this->extractOrderRiderPhone($json),
            'rider_to_store_eta' => $this->extractOrderRiderToStoreEta($json),
        ];
    }

    private function mergeMissingDeliveryStateWithStoredValues(Order $order, array $deliveryState): array
    {
        $trackedFields = [
            'delivery_type',
            'fulfillment_mode',
            'expected_arrived_eta',
            'pickup_code',
            'locator',
            'handover_page_url',
            'virtual_phone_number',
            'handover_code',
            'rider_name',
            'rider_phone',
            'rider_to_store_eta',
        ];

        $requiresFallback = false;
        foreach ($trackedFields as $fieldName) {
            if ($this->normalizeIncomingFood99Value($deliveryState[$fieldName] ?? null) !== '') {
                continue;
            }

            $requiresFallback = true;
            break;
        }

        if (!$requiresFallback) {
            return $deliveryState;
        }

        $storedState = $this->getStoredOrderIntegrationState($order);

        foreach ($trackedFields as $fieldName) {
            if ($this->normalizeIncomingFood99Value($deliveryState[$fieldName] ?? null) !== '') {
                continue;
            }

            $storedValue = $this->normalizeIncomingFood99Value($storedState[$fieldName] ?? null);
            if ($storedValue === '') {
                continue;
            }

            $deliveryState[$fieldName] = $storedValue;
        }

        return $deliveryState;
    }

    private function searchPayloadValueByKeys(mixed $payload, array $keys): ?string
    {
        if (!is_array($payload)) {
            return null;
        }

        foreach ($keys as $key) {
            if (array_key_exists($key, $payload) && !is_array($payload[$key])) {
                $value = $this->normalizeIncomingFood99Value($payload[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach ($payload as $value) {
            if (!is_array($value)) {
                continue;
            }

            $resolved = $this->searchPayloadValueByKeys($value, $keys);
            if ($resolved !== null && $resolved !== '') {
                return $resolved;
            }
        }

        return null;
    }

    private function extractOrderEventTimestamp(array $json): string
    {
        $timestamp = $this->searchPayloadValueByKeys($json, [
            'event_time',
            'eventTime',
            'event_timestamp',
            'eventTimestamp',
            'update_time',
            'updateTime',
            'create_time',
            'createTime',
            'created_at',
            'createdAt',
            'timestamp',
            'time',
        ]);

        if ($timestamp === null || $timestamp === '') {
            return date('Y-m-d H:i:s');
        }

        if (ctype_digit($timestamp)) {
            $unix = (int) $timestamp;
            if ($unix > 1000000000000) {
                $unix = (int) floor($unix / 1000);
            }

            if ($unix > 0) {
                return date('Y-m-d H:i:s', $unix);
            }
        }

        return $timestamp;
    }

    private function extractOrderDeliveryStatus(array $json): ?string
    {
        return $this->searchPayloadValueByKeys($json, [
            'delivery_status',
            'deliveryStatus',
            'status_desc',
            'statusDesc',
            'status',
        ]);
    }

    private function extractOrderDeliveryType(array $json): ?string
    {
        return $this->searchPayloadValueByKeys($json, [
            'delivery_type',
            'deliveryType',
        ]);
    }

    private function extractOrderFulfillmentMode(array $json): ?string
    {
        return $this->searchPayloadValueByKeys($json, [
            'fulfillment_mode',
            'fulfillmentMode',
        ]);
    }

    private function extractOrderExpectedArrivedEta(array $json): ?string
    {
        return $this->searchPayloadValueByKeys($json, [
            'expected_arrived_eta',
            'expectedArrivedEta',
            'delivery_eta',
            'deliveryEta',
        ]);
    }

    private function extractOrderPickupCode(array $json): ?string
    {
        return $this->searchPayloadValueByKeys($json, [
            'pickup_code',
            'pickupCode',
        ]);
    }

    private function extractOrderLocator(array $json): ?string
    {
        return $this->searchPayloadValueByKeys($json, [
            'locator',
        ]);
    }

    private function extractOrderHandoverPageUrl(array $json): ?string
    {
        return $this->searchPayloadValueByKeys($json, [
            'handover_page_url',
            'handoverPageUrl',
        ]);
    }

    private function extractOrderVirtualPhoneNumber(array $json): ?string
    {
        return $this->searchPayloadValueByKeys($json, [
            'virtual_phone_number',
            'virtualPhoneNumber',
        ]);
    }

    private function extractOrderHandoverCode(array $json): ?string
    {
        return $this->searchPayloadValueByKeys($json, [
            'handover_code',
            'handoverCode',
        ]);
    }

    private function extractOrderRiderName(array $json): ?string
    {
        return $this->searchPayloadValueByKeys($json, [
            'rider_name',
            'riderName',
        ]);
    }

    private function extractOrderRiderPhone(array $json): ?string
    {
        return $this->searchPayloadValueByKeys($json, [
            'rider_phone',
            'riderPhone',
        ]);
    }

    private function extractOrderRiderToStoreEta(array $json): ?string
    {
        return $this->searchPayloadValueByKeys($json, [
            'rider_to_B_ETA',
            'rider_to_b_eta',
            'riderToBEta',
            'rider_to_store_eta',
            'riderToStoreEta',
        ]);
    }

    private function resolveOrderDeliveryFlags(array $state): array
    {
        $deliveryType = trim((string) ($state['delivery_type'] ?? ''));
        $locator = trim((string) ($state['locator'] ?? ''));
        $handoverPageUrl = trim((string) ($state['handover_page_url'] ?? ''));
        $virtualPhoneNumber = trim((string) ($state['virtual_phone_number'] ?? ''));
        $handoverCode = trim((string) ($state['handover_code'] ?? ''));

        $isStoreDelivery = false;
        $isPlatformDelivery = false;
        $deliveryLabel = 'Indefinido';

        // 99Food: delivery_type=2 is store/self delivery, delivery_type=1 is platform delivery.
        if ($deliveryType === '2') {
            $isStoreDelivery = true;
            $deliveryLabel = 'Entrega da loja';
        } elseif ($deliveryType === '1') {
            $isPlatformDelivery = true;
            $deliveryLabel = 'Entrega 99';
        } elseif ($locator !== '' || $handoverPageUrl !== '' || $virtualPhoneNumber !== '') {
            // Locator and handover confirmation data belong to store/self delivery flow.
            $isStoreDelivery = true;
            $deliveryLabel = 'Entrega da loja';
        } elseif ($handoverCode !== '') {
            // Store delivery frequently carries verification codes without courier tracking fields.
            $isStoreDelivery = true;
            $deliveryLabel = 'Entrega da loja';
        }

        return [
            'is_store_delivery' => $isStoreDelivery,
            'is_platform_delivery' => $isPlatformDelivery,
            'delivery_label' => $deliveryLabel,
        ];
    }

    private function resolveAllowsManualDeliveryCompletion(array $state): bool
    {
        if (empty($state['is_store_delivery'])) {
            return false;
        }

        $remoteOrderState = strtolower(trim((string) ($state['remote_order_state'] ?? '')));
        if (in_array($remoteOrderState, ['delivered', 'finished', 'closed', 'complete', 'completed', 'cancelled', 'canceled'], true)) {
            return false;
        }

        if ($remoteOrderState !== '') {
            return in_array($remoteOrderState, ['ready', 'picked_up', 'delivering', 'arriving'], true);
        }

        $deliveryStatus = trim((string) ($state['remote_delivery_status'] ?? ''));
        if ($deliveryStatus === '') {
            return false;
        }

        if (is_numeric($deliveryStatus)) {
            $statusCode = (int) $deliveryStatus;
            return $statusCode >= 400 && $statusCode < 600;
        }

        return !$this->isDeliveredRemoteState($deliveryStatus)
            && (str_contains(strtolower($deliveryStatus), 'deliver') || str_contains(strtolower($deliveryStatus), 'arriv'));
    }

    private function extractOrderCancelReason(array $json): ?string
    {
        return $this->searchPayloadValueByKeys($json, [
            'reason',
            'cancel_reason',
            'cancelReason',
            'reason_desc',
            'reasonDesc',
            'message',
        ]);
    }

    private function extractOrderCancelCode(array $json): ?string
    {
        return $this->searchPayloadValueByKeys($json, [
            'reason_id',
            'reasonId',
            'cancel_code',
            'cancelCode',
            'code',
        ]);
    }

    private function isDeliveredRemoteState(?string $value): bool
    {
        $normalizedValue = strtolower(trim((string) $value));
        if ($normalizedValue === '') {
            return false;
        }

        if (is_numeric($normalizedValue)) {
            // 99Food logistics: 160 is terminal delivery completed.
            return (int) $normalizedValue === 160;
        }

        foreach (['deliver', 'finish', 'complete', 'done', 'success'] as $terminalToken) {
            if (str_contains($normalizedValue, $terminalToken)) {
                return true;
            }
        }

        return false;
    }

    private function storeOrderRemoteSnapshot(Order $order, string $entryKey, array $payload): void
    {
        $otherInformations = (array) $order->getOtherInformations(true);
        $otherInformations[$entryKey] = $payload;
        $otherInformations['latest_event_type'] = $entryKey;
        $order->addOtherInformations(self::APP_CONTEXT, $otherInformations);
        $this->entityManager->persist($order);
    }

    private function isTerminalLocalOrderStatus(Order $order): bool
    {
        $currentRealStatus = strtolower(trim((string) ($order->getStatus()?->getRealStatus() ?? '')));

        return in_array($currentRealStatus, ['closed', 'canceled', 'cancelled'], true);
    }

    private function applyLocalStatus(Order $order, string $realStatus, string $statusName): void
    {
        $normalizedRealStatus = strtolower(trim($realStatus));
        $normalizedStatusName = strtolower(trim($statusName));
        $currentRealStatus = strtolower(trim((string) ($order->getStatus()?->getRealStatus() ?? '')));
        $currentStatusName = strtolower(trim((string) ($order->getStatus()?->getStatus() ?? '')));

        if ($currentRealStatus === $normalizedRealStatus && $currentStatusName === $normalizedStatusName) {
            return;
        }

        $status = $this->statusService->discoveryStatus($normalizedRealStatus, $normalizedStatusName, 'order');
        if (!$status) {
            return;
        }

        $order->setStatus($status);
        $this->entityManager->persist($order);
    }

    private function applyLocalOpenStatus(Order $order): void
    {
        if ($this->isTerminalLocalOrderStatus($order)) {
            return;
        }

        $this->applyLocalStatus($order, 'open', 'open');
    }

    private function applyLocalPreparingStatus(Order $order): void
    {
        if ($this->isTerminalLocalOrderStatus($order)) {
            return;
        }

        $this->applyLocalStatus($order, 'open', 'preparing');
    }

    private function applyLocalReadyStatus(Order $order): void
    {
        if ($this->isTerminalLocalOrderStatus($order)) {
            return;
        }

        $this->applyLocalStatus($order, 'pending', 'ready');
    }

    private function applyLocalWayStatus(Order $order): void
    {
        if ($this->isTerminalLocalOrderStatus($order)) {
            return;
        }

        $this->applyLocalStatus($order, 'pending', 'way');
    }

    private function applyLocalLifecycleStatusFromRemoteState(Order $order, ?string $remoteState): void
    {
        $normalizedRemoteState = $this->normalizeRemoteOrderState($remoteState);

        match ($normalizedRemoteState) {
            'new' => $this->applyLocalOpenStatus($order),
            'accepted', 'preparing' => $this->applyLocalPreparingStatus($order),
            'ready', 'courier_to_store' => $this->applyLocalReadyStatus($order),
            'picked_up', 'delivering', 'arriving' => $this->applyLocalWayStatus($order),
            default => null,
        };
    }

    private function applyLocalCanceledStatus(Order $order): void
    {
        $currentRealStatus = strtolower(trim((string) ($order->getStatus()?->getRealStatus() ?? '')));
        if (in_array($currentRealStatus, ['canceled', 'cancelled'], true)) {
            return;
        }

        $status = $this->statusService->discoveryStatus('canceled', 'canceled', 'order');
        if (!$status) {
            return;
        }

        $order->setStatus($status);
        $this->entityManager->persist($order);
    }

    private function applyLocalClosedStatus(Order $order): void
    {
        $currentRealStatus = strtolower(trim((string) ($order->getStatus()?->getRealStatus() ?? '')));
        if ($currentRealStatus === 'closed') {
            return;
        }

        $status = $this->statusService->discoveryStatus('closed', 'closed', 'order');
        if (!$status) {
            return;
        }

        $order->setStatus($status);
        $this->entityManager->persist($order);
    }

    private function findIntegratedOrderFromPayload(array $json): ?Order
    {
        $identifiers = $this->extractIncomingOrderIdentifiers($json);

        return $this->findExistingIntegratedOrder($identifiers['order_id'], $identifiers['order_code']);
    }

    private function handleOrderCancelEvent(array $json): ?Order
    {
        return $this->handleGenericOrderEvent($json, 'orderCancel');
    }

    private function handleOrderFinishEvent(array $json): ?Order
    {
        return $this->handleGenericOrderEvent($json, 'orderFinish');
    }

    private function handleDeliveryStatusEvent(array $json): ?Order
    {
        return $this->handleGenericOrderEvent($json, 'deliveryStatus');
    }

    private function handleFallbackOrderEvent(Integration $integration, array $json): ?Order
    {
        $eventType = $this->normalizeIncomingFood99Value($json['type'] ?? null);

        if ($eventType === '') {
            self::$logger->warning('Food99 payload ignored because event type is empty', $this->buildLogContext($integration, $json));
            return null;
        }

        $updatedOrder = $this->handleGenericOrderEvent($json, $eventType, false);
        if ($updatedOrder instanceof Order) {
            return $updatedOrder;
        }

        if ($this->isCreationLikeOrderEventType($eventType)) {
            self::$logger->info('Food99 fallback event routed to addOrder flow', $this->buildLogContext($integration, $json, [
                'event_type' => $eventType,
            ]));

            return $this->addOrder($json);
        }

        self::$logger->info('Food99 event ignored because it has no matching local order in current flow', $this->buildLogContext($integration, $json, [
            'event_type' => $eventType,
        ]));

        return null;
    }

    private function handleGenericOrderEvent(array $json, string $eventType, bool $warnWhenOrderMissing = true): ?Order
    {
        $order = $this->findIntegratedOrderFromPayload($json);
        if (!$order instanceof Order) {
            if ($warnWhenOrderMissing) {
                self::$logger->warning(
                    sprintf('Food99 %s received without a matching local order', $eventType),
                    $this->buildLogContext(null, $json, ['event_type' => $eventType])
                );
            }

            return null;
        }

        $deliveryStatus = $this->extractOrderDeliveryStatus($json);
        $incomingRemoteState = $this->resolveCanonicalRemoteOrderState($eventType, $deliveryStatus);
        $currentRemoteState = $this->getFood99OrderExtraDataValue((int) $order->getId(), 'remote_order_state');
        $remoteState = $this->mergeRemoteOrderStateWithCurrent($currentRemoteState, $incomingRemoteState);
        $eventTimestamp = $this->extractOrderEventTimestamp($json);
        $incomingIsCanceled = $this->shouldApplyLocalCanceledStatus($incomingRemoteState, $eventType);
        $incomingIsClosed = !$incomingIsCanceled && $this->shouldApplyLocalClosedStatus($incomingRemoteState, $eventType, $deliveryStatus);
        $isCanceled = $incomingIsCanceled || $this->isCancellationRemoteOrderState($remoteState);
        $isClosed = !$isCanceled && ($incomingIsClosed || $this->isClosedRemoteOrderState($remoteState));

        $this->storeOrderRemoteSnapshot($order, $eventType !== '' ? $eventType : 'unknownEvent', $json);
        $this->syncOrderComments($order, $this->extractOrderRemark($json));

        $integrationState = [
            'last_event_type' => $eventType !== '' ? $eventType : 'unknown',
            'last_event_at' => $eventTimestamp,
        ];

        if ($deliveryStatus !== null && trim($deliveryStatus) !== '') {
            $integrationState['remote_delivery_status'] = $deliveryStatus;
        }

        if ($remoteState !== null && trim($remoteState) !== '') {
            $integrationState['remote_order_state'] = $remoteState;
        }

        if ($incomingIsCanceled) {
            $integrationState['cancel_code'] = $this->extractOrderCancelCode($json);
            $integrationState['cancel_reason'] = $this->extractOrderCancelReason($json);
        }

        $deliveryState = $this->mergeMissingDeliveryStateWithStoredValues(
            $order,
            $this->extractOrderDeliveryStateFields($json)
        );

        $this->persistOrderIntegrationState($order, array_merge(
            $integrationState,
            $deliveryState
        ));

        if ($isCanceled) {
            $this->applyLocalCanceledStatus($order);
        } elseif ($isClosed) {
            $this->applyLocalClosedStatus($order);
        } else {
            $this->applyLocalLifecycleStatusFromRemoteState($order, $remoteState);
        }

        $this->entityManager->flush();

        return $order;
    }

    private function isCreationLikeOrderEventType(string $eventType): bool
    {
        $normalized = strtolower(trim($eventType));
        if ($normalized === '') {
            return false;
        }

        if ($normalized === 'placed' || $normalized === 'orderplaced') {
            return true;
        }

        if (!str_contains($normalized, 'order')) {
            return false;
        }

        return str_contains($normalized, 'new')
            || str_contains($normalized, 'create')
            || str_contains($normalized, 'placed');
    }

    private function normalizeEventType(string $eventType): string
    {
        return strtolower(trim($eventType));
    }

    private function isPartialCancellationEventType(string $eventType): bool
    {
        $normalized = $this->normalizeEventType($eventType);

        return in_array($normalized, [
            'orderpartialcancel',
            'partialcancel',
            'itempartialcancel',
            'orderitemcancel',
        ], true);
    }

    private function isCancellationRequestEventType(string $eventType): bool
    {
        $normalized = $this->normalizeEventType($eventType);

        return in_array($normalized, [
            'ordercancelapply',
            'ordercancelrequest',
            'cancelapply',
            'cancelrequest',
        ], true);
    }

    private function isFinalCancellationEventType(string $eventType): bool
    {
        $normalized = $this->normalizeEventType($eventType);
        if ($normalized === '') {
            return false;
        }

        if ($this->isPartialCancellationEventType($normalized) || $this->isCancellationRequestEventType($normalized)) {
            return false;
        }

        if (in_array($normalized, ['ordercancel', 'cancel', 'ordercancelled', 'ordercanceled'], true)) {
            return true;
        }

        if (!str_contains($normalized, 'cancel')) {
            return false;
        }

        return str_contains($normalized, 'confirm')
            || str_contains($normalized, 'success')
            || str_contains($normalized, 'finish')
            || str_contains($normalized, 'complete')
            || str_contains($normalized, 'done');
    }

    private function resolveCanonicalRemoteOrderState(string $eventType, ?string $deliveryStatus = null): ?string
    {
        $normalizedEventType = $this->normalizeEventType($eventType);

        if ($normalizedEventType === '') {
            return $this->resolveRemoteOrderStateFromDeliveryStatus($deliveryStatus);
        }

        if ($this->isPartialCancellationEventType($normalizedEventType)) {
            return $this->resolveRemoteOrderStateFromDeliveryStatus($deliveryStatus) ?? 'partial_cancel';
        }

        if ($this->isCancellationRequestEventType($normalizedEventType)) {
            return $this->resolveRemoteOrderStateFromDeliveryStatus($deliveryStatus) ?? 'cancel_requested';
        }

        if ($this->isFinalCancellationEventType($normalizedEventType)) {
            return 'cancelled';
        }

        if ($normalizedEventType === 'ordernew') {
            return 'new';
        }

        if ($normalizedEventType === 'orderfinish' || str_contains($normalizedEventType, 'finish') || str_contains($normalizedEventType, 'complete')) {
            return 'finished';
        }

        if ($normalizedEventType === 'deliverystatus') {
            return $this->resolveRemoteOrderStateFromDeliveryStatus($deliveryStatus);
        }

        if (str_contains($normalizedEventType, 'ready') || str_contains($normalizedEventType, 'prepared')) {
            return 'ready';
        }

        if (str_contains($normalizedEventType, 'prepar') || str_contains($normalizedEventType, 'cook') || str_contains($normalizedEventType, 'process')) {
            return 'preparing';
        }

        if (str_contains($normalizedEventType, 'accept') || str_contains($normalizedEventType, 'confirm')) {
            return 'accepted';
        }

        if (str_contains($normalizedEventType, 'pickup')) {
            return 'picked_up';
        }

        if (str_contains($normalizedEventType, 'deliver') || str_contains($normalizedEventType, 'courier') || str_contains($normalizedEventType, 'ship') || str_contains($normalizedEventType, 'dispatch')) {
            return $this->resolveRemoteOrderStateFromDeliveryStatus($deliveryStatus) ?? 'delivering';
        }

        return $this->resolveRemoteOrderStateFromDeliveryStatus($deliveryStatus);
    }

    private function resolveRemoteOrderStateFromDeliveryStatus(?string $deliveryStatus): ?string
    {
        $normalized = strtolower(trim((string) $deliveryStatus));
        if ($normalized === '') {
            return null;
        }

        if (is_numeric($normalized)) {
            $statusCode = (int) $normalized;
            if ($statusCode === 160 || $statusCode >= 600) {
                return 'delivered';
            }
            if ($statusCode === 120) {
                return 'courier_to_store';
            }
            if ($statusCode >= 400) {
                return 'delivering';
            }
            if ($statusCode >= 300) {
                return 'ready';
            }
            if ($statusCode >= 200) {
                return 'accepted';
            }
            if ($statusCode >= 100) {
                return 'new';
            }
        }

        if ($this->isDeliveredRemoteState($normalized)) {
            return 'delivered';
        }

        if (str_contains($normalized, 'cancel')) {
            return 'cancelled';
        }

        if (str_contains($normalized, 'pickup') || str_contains($normalized, 'collect')) {
            return 'picked_up';
        }

        if (str_contains($normalized, 'arriv')) {
            return 'arriving';
        }

        if (str_contains($normalized, 'transit') || str_contains($normalized, 'courier') || str_contains($normalized, 'dispatch') || str_contains($normalized, 'ship') || str_contains($normalized, 'deliver')) {
            return 'delivering';
        }

        return null;
    }

    private function normalizeRemoteOrderState(?string $state): string
    {
        return strtolower(trim((string) $state));
    }

    private function isCancellationRemoteOrderState(?string $state): bool
    {
        $normalized = $this->normalizeRemoteOrderState($state);
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, ['cancelled', 'canceled', 'cancel_requested', 'partial_cancel'], true);
    }

    private function isClosedRemoteOrderState(?string $state): bool
    {
        $normalized = $this->normalizeRemoteOrderState($state);
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, ['delivered', 'finished', 'closed', 'complete', 'completed'], true);
    }

    private function isTerminalRemoteOrderState(?string $state): bool
    {
        return $this->isClosedRemoteOrderState($state) || $this->isCancellationRemoteOrderState($state);
    }

    private function getRemoteOrderStatePriority(string $normalizedState): int
    {
        return match ($normalizedState) {
            'new' => 10,
            'accepted' => 20,
            'preparing' => 30,
            'ready' => 40,
            'courier_to_store' => 42,
            'partial_cancel', 'cancel_requested' => 45,
            'picked_up' => 50,
            'delivering' => 60,
            'arriving' => 70,
            'delivered', 'finished', 'closed', 'complete', 'completed', 'cancelled', 'canceled' => 100,
            default => 0,
        };
    }

    private function mergeRemoteOrderStateWithCurrent(?string $currentState, ?string $incomingState): ?string
    {
        $current = $this->normalizeRemoteOrderState($currentState);
        $incoming = $this->normalizeRemoteOrderState($incomingState);

        if ($incoming === '') {
            return $current !== '' ? $current : null;
        }

        if ($current === '') {
            return $incoming;
        }

        if ($this->isCancellationRemoteOrderState($incoming)) {
            return $incoming;
        }

        if ($this->isCancellationRemoteOrderState($current)) {
            return $current;
        }

        if ($this->isTerminalRemoteOrderState($current) && !$this->isTerminalRemoteOrderState($incoming)) {
            return $current;
        }

        if ($this->isTerminalRemoteOrderState($incoming)) {
            return $incoming;
        }

        $currentPriority = $this->getRemoteOrderStatePriority($current);
        $incomingPriority = $this->getRemoteOrderStatePriority($incoming);

        if ($incomingPriority === 0 || $incomingPriority < $currentPriority) {
            return $current;
        }

        return $incoming;
    }

    private function shouldApplyLocalCanceledStatus(?string $remoteState, string $eventType): bool
    {
        $normalizedState = strtolower(trim((string) $remoteState));
        if (in_array($normalizedState, ['cancelled', 'canceled'], true)) {
            return true;
        }

        return $this->isFinalCancellationEventType($eventType);
    }

    private function shouldApplyLocalClosedStatus(?string $remoteState, string $eventType, ?string $deliveryStatus): bool
    {
        $normalizedState = strtolower(trim((string) $remoteState));
        if (in_array($normalizedState, ['delivered', 'finished', 'closed', 'complete', 'completed'], true)) {
            return true;
        }

        $normalizedEventType = strtolower(trim($eventType));
        if (str_contains($normalizedEventType, 'finish') || str_contains($normalizedEventType, 'complete')) {
            return true;
        }

        return $this->isDeliveredRemoteState($deliveryStatus);
    }

    private function persistProviderIntegrationState(People $provider, array $fields): void
    {
        foreach ($fields as $fieldName => $value) {
            $this->upsertFood99ExtraDataValue('People', (int) $provider->getId(), (string) $fieldName, $value);
        }
    }

    private function persistProviderLastError(People $provider, mixed $code = null, mixed $message = null): void
    {
        $this->persistProviderIntegrationState($provider, [
            'last_error_code' => $code,
            'last_error_message' => $message,
        ]);
    }

    private function persistProviderStoreState(People $provider, array $storeData): void
    {
        $shopId = isset($storeData['shop_id']) ? (string) $storeData['shop_id'] : $this->getIntegratedStoreCode($provider);
        $bizStatus = isset($storeData['biz_status']) ? (int) $storeData['biz_status'] : null;
        $subBizStatus = isset($storeData['sub_biz_status']) ? (int) $storeData['sub_biz_status'] : null;
        $storeStatus = isset($storeData['store_status']) ? (int) $storeData['store_status'] : null;

        $this->persistProviderIntegrationState($provider, [
            'code' => $shopId,
            'biz_status' => $bizStatus,
            'sub_biz_status' => $subBizStatus,
            'store_status' => $storeStatus,
            'remote_connected' => 1,
            'online' => $bizStatus === 1 ? 1 : 0,
            'last_sync_at' => date('Y-m-d H:i:s'),
            'last_error_code' => '',
            'last_error_message' => '',
        ]);
    }

    private function persistProviderMenuState(People $provider, array $menuData, mixed $taskId = null): void
    {
        $menus = is_array($menuData['menus'] ?? null) ? $menuData['menus'] : [];
        $items = is_array($menuData['items'] ?? null) ? $menuData['items'] : [];

        $this->persistProviderIntegrationState($provider, [
            'menu_count' => count($menus),
            'menu_item_count' => count($items),
            'last_menu_task_id' => $taskId,
            'last_sync_at' => date('Y-m-d H:i:s'),
            'remote_connected' => 1,
            'last_error_code' => '',
            'last_error_message' => '',
        ]);
    }

    private function syncPublishedProductsForProvider(People $provider, array $publishedItemIds): void
    {
        $publishedItemIds = array_values(array_unique(array_filter(array_map(
            fn(mixed $itemId) => $this->normalizeExtraDataValue($itemId),
            $publishedItemIds
        ))));
        $publishedItemIdSet = array_flip($publishedItemIds);
        $localCandidateIds = [];

        foreach ($this->fetchMenuProducts($provider) as $row) {
            $productId = (int) ($row['id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $candidateId = trim((string) ($row['food99_code'] ?? '')) ?: (string) $productId;
            $localCandidateIds[] = $candidateId;
            $published = isset($publishedItemIdSet[$candidateId]);

            if ($published && empty($row['food99_code'])) {
                $this->persistLocalFoodCodeByEntity('Product', $productId, $candidateId);
            }

            $this->upsertFood99ExtraDataValue('Product', $productId, 'published', $published ? '1' : '0');
        }

        $remoteOnlyItemCount = count(array_diff($publishedItemIds, array_unique($localCandidateIds)));
        $this->persistProviderIntegrationState($provider, [
            'remote_only_item_count' => $remoteOnlyItemCount,
            'last_sync_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function persistProviderMenuUploadSubmission(People $provider, array $response, mixed $taskId = null): void
    {
        $taskData = is_array($response['data'] ?? null) ? $response['data'] : [];
        $taskStatus = isset($taskData['status']) ? (string) $taskData['status'] : '0';
        $taskMessage = trim((string) ($taskData['message'] ?? 'waiting'));
        $publishState = $this->resolveMenuTaskProgressState($taskData);

        $this->persistProviderIntegrationState($provider, [
            'last_menu_task_id' => $taskId,
            'last_menu_task_status' => $taskStatus,
            'last_menu_task_message' => $taskMessage,
            'last_menu_task_checked_at' => date('Y-m-d H:i:s'),
            'last_menu_publish_state' => $publishState === 'completed' ? 'submitted' : $publishState,
            'last_error_code' => '',
            'last_error_message' => '',
        ]);
    }

    private function extractMenuTaskFailureMessage(array $taskData): ?string
    {
        foreach (($taskData['operationList'] ?? []) as $operation) {
            if (!is_array($operation)) {
                continue;
            }

            foreach (($operation['failedList'] ?? []) as $failedItem) {
                if (!is_array($failedItem)) {
                    continue;
                }

                $message = trim((string) ($failedItem['message'] ?? ''));
                if ($message !== '') {
                    return $message;
                }
            }
        }

        return null;
    }

    private function normalizeMenuTaskResponse(array $response, int|string|null $fallbackTaskId = null): array
    {
        if (!is_array($response['data'] ?? null)) {
            return $response;
        }

        $taskData = $response['data'];

        if (array_key_exists('taskID', $taskData) && $taskData['taskID'] !== null && $taskData['taskID'] !== '') {
            $taskData['taskID'] = (string) $taskData['taskID'];
        } elseif ($fallbackTaskId !== null && $fallbackTaskId !== '') {
            $taskData['taskID'] = (string) $fallbackTaskId;
        }

        if (array_key_exists('taskId', $taskData) && $taskData['taskId'] !== null && $taskData['taskId'] !== '') {
            $taskData['taskId'] = (string) $taskData['taskId'];
        }

        if (array_key_exists('appShopID', $taskData) && $taskData['appShopID'] !== null && $taskData['appShopID'] !== '') {
            $taskData['appShopID'] = (string) $taskData['appShopID'];
        }

        if (array_key_exists('app_shop_id', $taskData) && $taskData['app_shop_id'] !== null && $taskData['app_shop_id'] !== '') {
            $taskData['app_shop_id'] = (string) $taskData['app_shop_id'];
        }

        $response['data'] = $taskData;

        return $response;
    }

    private function resolveMenuTaskProgressState(array $taskData): string
    {
        if (empty($taskData)) {
            return 'submitted';
        }

        $status = isset($taskData['status']) ? (int) $taskData['status'] : null;
        $message = strtolower(trim((string) ($taskData['message'] ?? '')));
        $failureMessage = strtolower(trim((string) ($this->extractMenuTaskFailureMessage($taskData) ?? '')));

        if ($status === 2 || $failureMessage !== '' || str_contains($message, 'fail') || str_contains($message, 'error')) {
            return 'failed';
        }

        if (
            $status === 1
            || str_contains($message, 'success')
            || str_contains($message, 'complete')
            || str_contains($message, 'done')
        ) {
            return 'completed';
        }

        if ($message === 'waiting' || str_contains($message, 'wait') || $status === 0) {
            return 'processing';
        }

        if (str_contains($message, 'process') || str_contains($message, 'running')) {
            return 'processing';
        }

        return 'completed';
    }

    private function persistProviderMenuTaskState(People $provider, array $response, int|string|null $fallbackTaskId = null): string
    {
        $taskData = is_array($response['data'] ?? null) ? $response['data'] : [];
        $taskId = $taskData['taskID'] ?? $taskData['taskId'] ?? $fallbackTaskId;
        $taskStatus = isset($taskData['status']) ? (string) $taskData['status'] : '';
        $taskMessage = trim((string) ($this->extractMenuTaskFailureMessage($taskData) ?: ($taskData['message'] ?? $response['errmsg'] ?? '')));
        $publishState = $this->resolveMenuTaskProgressState($taskData);

        $this->persistProviderIntegrationState($provider, [
            'last_menu_task_id' => $taskId,
            'last_menu_task_status' => $taskStatus,
            'last_menu_task_message' => $taskMessage,
            'last_menu_task_checked_at' => date('Y-m-d H:i:s'),
            'last_menu_publish_state' => $publishState,
        ]);

        if ($publishState === 'failed') {
            $this->persistProviderLastError(
                $provider,
                $taskStatus !== '' ? 'menu_task:' . $taskStatus : 'menu_task:failed',
                $taskMessage !== '' ? $taskMessage : 'A publicacao do cardapio falhou na 99Food.'
            );
        }

        return $publishState;
    }

    private function markProviderMenuPublished(People $provider, ?string $message = null): void
    {
        $this->persistProviderIntegrationState($provider, [
            'last_menu_publish_state' => 'published',
            'last_menu_task_checked_at' => date('Y-m-d H:i:s'),
            'last_menu_task_message' => $message ?: 'Cardapio publicado com sucesso no catalogo remoto.',
            'last_error_code' => '',
            'last_error_message' => '',
        ]);
    }

    private function markProviderMenuSyncError(People $provider, ?string $message = null): void
    {
        $syncMessage = trim((string) ($message ?? ''));

        $this->persistProviderIntegrationState($provider, [
            'last_menu_publish_state' => 'sync_error',
            'last_menu_task_checked_at' => date('Y-m-d H:i:s'),
            'last_menu_task_message' => $syncMessage !== '' ? $syncMessage : 'A task concluiu, mas nao foi possivel confirmar o cardapio remoto.',
        ]);
    }

    private function persistProviderDeliveryAreaState(People $provider, array $deliveryAreaData): void
    {
        $areaGroups = is_array($deliveryAreaData['area_group'] ?? null) ? $deliveryAreaData['area_group'] : [];

        $this->persistProviderIntegrationState($provider, [
            'delivery_area_count' => count($areaGroups),
            'last_sync_at' => date('Y-m-d H:i:s'),
            'remote_connected' => 1,
            'last_error_code' => '',
            'last_error_message' => '',
        ]);
    }

    private function syncStoreStateFromResponse(People $provider, ?array $response): ?array
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : null;
        $errno = isset($response['errno']) ? (int) $response['errno'] : null;

        if ($errno === 0 && is_array($data)) {
            $this->persistProviderStoreState($provider, $data);
            return $response;
        }

        $this->persistProviderLastError($provider, $response['errno'] ?? null, $response['errmsg'] ?? null);

        return $response;
    }

    private function syncMenuStateFromResponse(People $provider, ?array $response, mixed $taskId = null): ?array
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : null;
        $errno = isset($response['errno']) ? (int) $response['errno'] : null;

        if ($errno === 0 && is_array($data)) {
            $this->persistProviderMenuState($provider, $data, $taskId);
            $this->syncPublishedProductsForProvider($provider, $this->resolvePublishedRemoteItemIds([
                'data' => $data,
            ]));
            return $response;
        }

        $this->persistProviderLastError($provider, $response['errno'] ?? null, $response['errmsg'] ?? null);

        return $response;
    }

    private function syncDeliveryAreaStateFromResponse(People $provider, ?array $response): ?array
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : null;
        $errno = isset($response['errno']) ? (int) $response['errno'] : null;

        if ($errno === 0 && is_array($data)) {
            $this->persistProviderDeliveryAreaState($provider, $data);
            return $response;
        }

        $this->persistProviderLastError($provider, $response['errno'] ?? null, $response['errmsg'] ?? null);

        return $response;
    }

    private function syncStoreStatusWebhook(array $json): void
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $candidateShopIds = array_values(array_unique(array_filter([
            $this->normalizeIncomingFood99Value($json['app_shop_id'] ?? null),
            $this->normalizeIncomingFood99Value($data['shop_id'] ?? null),
        ], static fn(string $value): bool => $value !== '')));

        if ($candidateShopIds === []) {
            self::$logger->warning('Food99 shopStatus webhook ignored because no shop identifier was provided', [
                'payload' => $json,
            ]);
            return;
        }

        $provider = null;
        foreach ($candidateShopIds as $candidateShopId) {
            $provider = $this->findFood99EntityByExtraData('People', 'code', $candidateShopId, People::class);
            if ($provider instanceof People) {
                break;
            }

            if (ctype_digit($candidateShopId)) {
                $provider = $this->entityManager->getRepository(People::class)->find((int) $candidateShopId);
                if ($provider instanceof People) {
                    break;
                }
            }
        }

        if (!$provider instanceof People) {
            self::$logger->warning('Food99 shopStatus webhook ignored because provider was not found', [
                'candidate_shop_ids' => $candidateShopIds,
            ]);
            return;
        }

        $this->persistProviderStoreState($provider, $data);
    }

    public function listProvidersWithFood99Binding(int $limit = 100): array
    {
        $this->init();

        $safeLimit = max(1, min($limit, 1000));
        $sql = <<<SQL
            SELECT DISTINCT ed.entity_id
            FROM extra_data ed
            INNER JOIN extra_fields ef ON ef.id = ed.extra_fields_id
            WHERE ef.context = :context
              AND LOWER(ed.entity_name) = 'people'
              AND (
                    (ef.field_name = 'code' AND ed.data_value <> '')
                    OR (ef.field_name = 'remote_connected' AND ed.data_value = '1')
              )
            ORDER BY ed.entity_id ASC
            LIMIT {$safeLimit}
        SQL;

        $rows = $this->entityManager->getConnection()->fetchFirstColumn($sql, [
            'context' => self::APP_CONTEXT,
        ]);

        $providers = [];
        foreach ($rows as $row) {
            if (!is_numeric($row)) {
                continue;
            }

            $provider = $this->entityManager->getRepository(People::class)->find((int) $row);
            if ($provider instanceof People) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    public function reconcileProviderState(People $provider, string $source = 'manual'): array
    {
        $this->init();

        $startedAt = microtime(true);
        $sync = $this->syncIntegrationState($provider);
        $errors = is_array($sync['errors'] ?? null) ? $sync['errors'] : [];
        $status = empty($errors) ? 'ok' : 'partial_error';
        $message = empty($errors)
            ? 'Reconciliacao concluida com sucesso.'
            : 'Reconciliacao concluida com inconsistencias em: ' . implode(', ', array_keys($errors));

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $this->persistProviderIntegrationState($provider, [
            'last_reconcile_at' => date('Y-m-d H:i:s'),
            'last_reconcile_status' => $status,
            'last_reconcile_message' => $message,
            'last_reconcile_source' => $source,
            'last_reconcile_duration_ms' => $durationMs,
        ]);

        return [
            'provider_id' => $provider->getId(),
            'status' => $status,
            'message' => $message,
            'duration_ms' => $durationMs,
            'errors' => $errors,
            'sync' => $sync,
        ];
    }

    public function getStoredOperationalSettings(People $provider): array
    {
        $this->init();

        $providerId = (int) $provider->getId();
        $normalize = static fn(mixed $value): ?string => (trim((string) $value) === '' ? null : trim((string) $value));

        return [
            'delivery_radius' => $normalize($this->getFood99ExtraDataValue('People', $providerId, 'store_delivery_radius')),
            'open_time' => $normalize($this->getFood99ExtraDataValue('People', $providerId, 'store_open_time')),
            'close_time' => $normalize($this->getFood99ExtraDataValue('People', $providerId, 'store_close_time')),
            'delivery_method' => $normalize($this->getFood99ExtraDataValue('People', $providerId, 'store_delivery_method')),
            'confirm_method' => $normalize($this->getFood99ExtraDataValue('People', $providerId, 'store_confirm_method')),
            'delivery_area_id' => $normalize($this->getFood99ExtraDataValue('People', $providerId, 'store_delivery_area_id')),
            'settings_synced_at' => $normalize($this->getFood99ExtraDataValue('People', $providerId, 'store_settings_synced_at')),
        ];
    }

    public function persistOperationalSettings(People $provider, array $settings, bool $allowEmpty = false): void
    {
        $this->init();

        $providerId = (int) $provider->getId();
        $fieldMap = [
            'delivery_radius' => 'store_delivery_radius',
            'open_time' => 'store_open_time',
            'close_time' => 'store_close_time',
            'delivery_method' => 'store_delivery_method',
            'confirm_method' => 'store_confirm_method',
            'delivery_area_id' => 'store_delivery_area_id',
        ];

        $persisted = false;
        foreach ($fieldMap as $settingKey => $fieldName) {
            if (!array_key_exists($settingKey, $settings)) {
                continue;
            }

            $normalized = trim((string) $settings[$settingKey]);
            if ($normalized === '' && !$allowEmpty) {
                continue;
            }

            $existing = trim((string) ($this->getFood99ExtraDataValue('People', $providerId, $fieldName) ?? ''));
            if ($existing === $normalized) {
                continue;
            }

            $this->upsertFood99ExtraDataValue('People', $providerId, $fieldName, $normalized);
            $persisted = true;
        }

        if ($persisted) {
            $this->upsertFood99ExtraDataValue('People', $providerId, 'store_settings_synced_at', date('Y-m-d H:i:s'));
        }
    }

    public function getLatestProviderOrderDeliveryType(People $provider): ?string
    {
        $this->init();

        $providerId = (int) $provider->getId();
        if ($providerId <= 0) {
            return null;
        }

        $sql = <<<SQL
            SELECT ed.data_value
            FROM orders o
            INNER JOIN extra_data ed
                ON ed.entity_id = o.id
               AND LOWER(ed.entity_name) = 'order'
            INNER JOIN extra_fields ef
                ON ef.id = ed.extra_fields_id
            WHERE o.provider_id = :providerId
              AND ef.context = :context
              AND ef.field_name = 'delivery_type'
            ORDER BY o.order_date DESC, o.id DESC, ed.id DESC
            LIMIT 1
        SQL;

        $value = $this->entityManager->getConnection()->fetchOne($sql, [
            'providerId' => $providerId,
            'context' => self::APP_CONTEXT,
        ]);

        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        if (!in_array($normalized, ['1', '2'], true)) {
            return null;
        }

        return $normalized;
    }

    public function getStoredIntegrationState(People $provider): array
    {
        $this->init();

        $bizStatus = $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'biz_status');
        $subBizStatus = $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'sub_biz_status');
        $storeStatus = $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'store_status');
        $food99Code = $this->getIntegratedStoreCode($provider);
        $menuCount = $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'menu_count');
        $menuItemCount = $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'menu_item_count');
        $deliveryAreaCount = $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'delivery_area_count');
        $remoteOnlyItemCount = $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'remote_only_item_count');

        return [
            'connected' => !empty($food99Code),
            'food99_code' => $food99Code,
            'app_shop_id' => (string) $provider->getId(),
            'biz_status' => is_numeric($bizStatus) ? (int) $bizStatus : null,
            'sub_biz_status' => is_numeric($subBizStatus) ? (int) $subBizStatus : null,
            'store_status' => is_numeric($storeStatus) ? (int) $storeStatus : null,
            'remote_connected' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'remote_connected') === '1',
            'online' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'online') === '1',
            'last_sync_at' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_sync_at'),
            'last_menu_task_id' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_menu_task_id'),
            'last_menu_task_status' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_menu_task_status'),
            'last_menu_task_message' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_menu_task_message'),
            'last_menu_task_checked_at' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_menu_task_checked_at'),
            'last_menu_publish_state' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_menu_publish_state'),
            'menu_count' => is_numeric($menuCount) ? (int) $menuCount : 0,
            'menu_item_count' => is_numeric($menuItemCount) ? (int) $menuItemCount : 0,
            'delivery_area_count' => is_numeric($deliveryAreaCount) ? (int) $deliveryAreaCount : 0,
            'remote_only_item_count' => is_numeric($remoteOnlyItemCount) ? (int) $remoteOnlyItemCount : 0,
            'last_error_code' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_error_code'),
            'last_error_message' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_error_message'),
            'last_webhook_event_id' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_webhook_event_id'),
            'last_webhook_event_type' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_webhook_event_type'),
            'last_webhook_event_at' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_webhook_event_at'),
            'last_webhook_received_at' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_webhook_received_at'),
            'last_webhook_processed_at' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_webhook_processed_at'),
            'last_webhook_order_id' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_webhook_order_id'),
            'last_webhook_shop_id' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_webhook_shop_id'),
            'last_reconcile_at' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_reconcile_at'),
            'last_reconcile_status' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_reconcile_status'),
            'last_reconcile_message' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_reconcile_message'),
            'last_reconcile_source' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_reconcile_source'),
            'last_reconcile_duration_ms' => $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_reconcile_duration_ms'),
        ];
    }

    public function getAuthorizationPage(array $payload): ?array
    {
        $this->init();

        return $this->call99AppEndpointWithResponse('POST', '/v1/auth/authorizationpage/getUrl', $payload);
    }

    public function bindStore(array $payload): ?array
    {
        $this->init();

        return $this->call99AppEndpointWithResponse('POST', '/v3/auth/authorization/shopBind', $payload);
    }

    public function listAuthorizedStores(array $payload = []): ?array
    {
        $this->init();

        return $this->call99AppEndpointWithResponse('POST', '/v3/auth/authorization/getAuthorizedShops', $payload);
    }

    public function listBindStores(array $payload = []): ?array
    {
        $this->init();

        return $this->call99AppEndpointWithResponse('POST', '/v1/shop/shop/list', $payload);
    }

    public function unbindStore(People $provider, array $payload = []): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('POST', '/v1/shop/shop/unbind', $payload, $provider);
    }

    public function setStoreOrderConfirmationMethod(People $provider, array $payload): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('POST', '/v1/shop/shop/setconfirmmethod', $payload, $provider);
    }

    public function getStoreOrderConfirmationMethod(People $provider): ?array
    {
        $this->init();

        $postResponse = $this->call99StoreEndpointWithResponse('POST', '/v1/shop/shop/getconfirmmethod', [], $provider);
        if ($this->isSuccessfulErrno($postResponse['errno'] ?? null)) {
            return $postResponse;
        }

        $getResponse = $this->call99StoreEndpointWithResponse('GET', '/v1/shop/shop/getconfirmmethod', [], $provider);
        if ($this->isSuccessfulErrno($getResponse['errno'] ?? null)) {
            return $getResponse;
        }

        return $postResponse ?: $getResponse;
    }

    public function getStoreDetails(People $provider): ?array
    {
        $this->init();

        return $this->syncStoreStateFromResponse(
            $provider,
            $this->call99StoreEndpointWithResponse('GET', '/v1/shop/shop/detail', [], $provider)
        );
    }

    public function updateStoreInformation(People $provider, array $payload): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('POST', '/v1/shop/shop/update', $payload, $provider);
    }

    public function getStoreCategories(People $provider): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('POST', '/v1/shop/shop/validCategories', [], $provider);
    }

    public function setStoreStatus(People $provider, int $bizStatus, ?int $autoSwitch = null): ?array
    {
        $this->init();

        $payload = [
            'biz_status' => $bizStatus,
        ];

        if ($autoSwitch !== null) {
            $payload['auto_switch'] = $autoSwitch;
        }

        $response = $this->call99StoreEndpointWithResponse('POST', '/v1/shop/shop/setStatus', $payload, $provider);
        if ($this->isSuccessfulErrno($response['errno'] ?? null)) {
            $this->persistProviderIntegrationState($provider, [
                'biz_status' => $bizStatus,
                'online' => $bizStatus === 1 ? 1 : 0,
                'remote_connected' => 1,
                'last_sync_at' => date('Y-m-d H:i:s'),
                'last_error_code' => '',
                'last_error_message' => '',
            ]);
        } else {
            $this->persistProviderLastError($provider, $response['errno'] ?? null, $response['errmsg'] ?? null);
        }

        return $response;
    }

    public function setStoreCancellationRefund(People $provider, array $payload): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('POST', '/v1/shop/apply/set', $payload, $provider);
    }

    public function markProviderConnected(People $provider, ?string $shopId = null): void
    {
        $this->init();

        $normalizedShopId = $this->normalizeIncomingFood99Value($shopId);
        $fields = [
            'remote_connected' => 1,
            'last_sync_at' => date('Y-m-d H:i:s'),
            'last_error_code' => '',
            'last_error_message' => '',
        ];

        if ($normalizedShopId !== '') {
            $fields['code'] = $normalizedShopId;
        }

        $this->persistProviderIntegrationState($provider, $fields);
    }

    public function clearProviderBindingState(People $provider): void
    {
        $this->init();

        $this->persistProviderIntegrationState($provider, [
            'code' => null,
            'remote_connected' => 0,
            'online' => 0,
            'biz_status' => null,
            'sub_biz_status' => null,
            'store_status' => null,
            'last_sync_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function getStoreMenuDetails(People $provider): ?array
    {
        $this->init();

        return $this->syncMenuStateFromResponse(
            $provider,
            $this->call99StoreEndpointWithResponse('GET', '/v3/item/item/list', [], $provider)
        );
    }

    public function updateMenuItem(People $provider, array $payload): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('POST', '/v3/item/item/updateItem', $payload, $provider);
    }

    public function updateMenuItemStatus(People $provider, array $payload): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('POST', '/v3/item/item/updateItemStatus', $payload, $provider);
    }

    public function updateModifierGroup(People $provider, array $payload): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('POST', '/v3/item/item/updateModifierGroup', $payload, $provider);
    }

    public function uploadImage(People $provider, array $payload): ?array
    {
        $this->init();

        return $this->call99StoreMultipartEndpointWithResponse('POST', '/v3/image/image/uploadImage', $payload, $provider);
    }

    public function getImageUploadInfoPageList(People $provider, array $payload = []): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('GET', '/v3/image/image/getImageUploadInfoPageList', $payload, $provider);
    }

    public function getOrderDetails(People $provider, string $orderId): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('GET', '/v1/order/order/detail', [
            'order_id' => $orderId,
        ], $provider);
    }

    public function confirmRemoteOrder(string $orderId, ?People $provider = null): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('POST', '/v1/order/order/confirm', [
            'order_id' => $orderId,
        ], $provider);
    }

    public function handleCancellationRequest(People $provider, array $payload): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('POST', '/v1/order/apply/cancel', $payload, $provider);
    }

    public function handleRefundRequest(People $provider, array $payload): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('POST', '/v1/order/apply/refund', $payload, $provider);
    }

    public function verifyOrder(People $provider, array $payload): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('POST', '/v1/order/order/verify', $payload, $provider);
    }

    public function confirmCashPayment(People $provider, array $payload): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('POST', '/v1/order/order/payConfirm', $payload, $provider);
    }

    public function listDeliveryAreas(People $provider): ?array
    {
        $this->init();

        return $this->syncDeliveryAreaStateFromResponse(
            $provider,
            $this->call99StoreEndpointWithResponse('GET', '/v1/shop/deliveryArea/list', [], $provider)
        );
    }

    public function addDeliveryArea(People $provider, array $payload): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('POST', '/v1/shop/deliveryArea/add', $payload, $provider);
    }

    public function updateDeliveryArea(People $provider, array $payload): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('POST', '/v1/shop/deliveryArea/update', $payload, $provider);
    }

    public function deleteDeliveryArea(People $provider, array $payload): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('POST', '/v1/shop/deliveryArea/delete', $payload, $provider);
    }

    public function getFinancialApiAuthtoken(array $payload): ?array
    {
        $this->init();

        return $this->request99WithResponse('POST', '/v3/auth/authtoken/signIn', $payload);
    }

    public function getBillData(array $payload): ?array
    {
        $this->init();

        return $this->request99WithResponse('POST', '/v3/finance/finance/getShopBillDetail', $payload);
    }

    public function getSettlementsData(array $payload): ?array
    {
        $this->init();

        return $this->request99WithResponse('POST', '/v3/finance/finance/getShopBillWeek', $payload);
    }

    private function normalizeProductIds(array $productIds): array
    {
        $normalized = [];

        foreach ($productIds as $productId) {
            if ($productId === null || $productId === '') {
                continue;
            }

            $digits = preg_replace('/\D+/', '', (string) $productId);
            if ($digits === '') {
                continue;
            }

            $normalized[] = (int) $digits;
        }

        return array_values(array_unique(array_filter($normalized)));
    }

    private function fetchMenuProducts(People $provider, array $productIds = []): array
    {
        $connection = $this->entityManager->getConnection();
        $params = [
            'providerId' => $provider->getId(),
            'food99Context' => self::APP_CONTEXT,
            'codeFieldName' => 'code',
            'publishedFieldName' => 'published',
        ];
        $sql = <<<SQL
            SELECT
                p.id,
                p.product AS product_name,
                p.description,
                p.price,
                p.type,
                p.active,
                c.id AS category_id,
                c.name AS category_name,
                pf.file_id AS cover_file_id,
                ed.data_value AS food99_code,
                ed_published.data_value AS food99_published,
                CASE WHEN EXISTS (
                    SELECT 1
                    FROM product_group pg_req
                    INNER JOIN product_group_product pgp_req
                        ON pgp_req.product_group_id = pg_req.id
                    INNER JOIN product child_req
                        ON child_req.id = pgp_req.product_child_id
                    WHERE pg_req.parent_product_id = p.id
                      AND pg_req.active = 1
                      AND pgp_req.active = 1
                      AND child_req.active = 1
                      AND pgp_req.product_type IN ('component', 'package')
                      AND COALESCE(pg_req.required, 0) = 1
                      AND COALESCE(pg_req.minimum, 0) >= 1
                ) THEN 1 ELSE 0 END AS has_required_modifiers
            FROM product p
            LEFT JOIN product_category pc ON pc.id = (
                SELECT MIN(pc2.id)
                FROM product_category pc2
                INNER JOIN category c2 ON c2.id = pc2.category_id
                WHERE pc2.product_id = p.id
                  AND c2.context = 'products'
            )
            LEFT JOIN category c ON c.id = pc.category_id
            LEFT JOIN product_file pf ON pf.id = (
                SELECT MIN(pf2.id)
                FROM product_file pf2
                WHERE pf2.product_id = p.id
            )
            LEFT JOIN extra_fields ef
                ON ef.context = :food99Context
               AND ef.field_name = :codeFieldName
            LEFT JOIN extra_data ed
                ON ed.extra_fields_id = ef.id
               AND ed.entity_name = 'Product'
               AND ed.entity_id = p.id
            LEFT JOIN extra_fields ef_published
                ON ef_published.context = :food99Context
               AND ef_published.field_name = :publishedFieldName
            LEFT JOIN extra_data ed_published
                ON ed_published.extra_fields_id = ef_published.id
               AND ed_published.entity_name = 'Product'
               AND ed_published.entity_id = p.id
            WHERE p.company_id = :providerId
              AND p.active = 1
              AND p.type IN ('manufactured', 'custom', 'product')
        SQL;

        if (!empty($productIds)) {
            $placeholders = [];
            foreach ($productIds as $index => $productId) {
                $key = 'productId' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $productId;
            }
            $sql .= ' AND p.id IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY COALESCE(c.name, \'\'), p.product ASC';

        return $connection->fetchAllAssociative($sql, $params);
    }

    private function fetchMenuModifierRows(People $provider, array $productIds = []): array
    {
        $parentIds = $this->normalizeProductIds($productIds);
        if (empty($parentIds)) {
            return [];
        }

        $connection = $this->entityManager->getConnection();
        $params = [
            'providerId' => $provider->getId(),
            'food99Context' => self::APP_CONTEXT,
            'codeFieldName' => 'code',
        ];

        $placeholders = [];
        foreach ($parentIds as $index => $productId) {
            $key = 'parentProductId' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $productId;
        }

        $sql = <<<SQL
            SELECT
                pg.parent_product_id AS parent_product_id,
                pg.id AS product_group_id,
                pg.product_group AS product_group_name,
                pg.required AS group_required,
                pg.minimum AS group_minimum,
                pg.maximum AS group_maximum,
                pg.group_order AS group_order,
                pgp.product_child_id AS child_product_id,
                pgp.price AS child_relation_price,
                child.product AS child_product_name,
                child.description AS child_description,
                child.price AS child_base_price,
                child_pf.file_id AS child_cover_file_id,
                ed_child.data_value AS child_food99_code
            FROM product_group pg
            INNER JOIN product_group_product pgp
                ON pgp.product_group_id = pg.id
            INNER JOIN product parent
                ON parent.id = pg.parent_product_id
            INNER JOIN product child
                ON child.id = pgp.product_child_id
            LEFT JOIN product_file child_pf ON child_pf.id = (
                SELECT MIN(pf2.id)
                FROM product_file pf2
                WHERE pf2.product_id = child.id
            )
            LEFT JOIN extra_fields ef_code
                ON ef_code.context = :food99Context
               AND ef_code.field_name = :codeFieldName
            LEFT JOIN extra_data ed_child
                ON ed_child.extra_fields_id = ef_code.id
               AND ed_child.entity_name = 'Product'
               AND ed_child.entity_id = child.id
            WHERE parent.company_id = :providerId
              AND parent.active = 1
              AND child.active = 1
              AND pg.active = 1
              AND pgp.active = 1
              AND pgp.product_type IN ('component', 'package')
              AND pg.parent_product_id IN (%s)
            ORDER BY
                pg.parent_product_id ASC,
                COALESCE(pg.group_order, 0) ASC,
                pg.id ASC,
                child.product ASC,
                child.id ASC
        SQL;

        $sql = sprintf($sql, implode(', ', $placeholders));

        return $connection->fetchAllAssociative($sql, $params);
    }

    private function buildMenuProductView(array $row): array
    {
        $productId = (int) ($row['id'] ?? 0);
        $productName = trim((string) ($row['product_name'] ?? ''));
        $categoryId = isset($row['category_id']) && $row['category_id'] !== null ? (int) $row['category_id'] : null;
        $categoryName = $row['category_name'] ? trim((string) $row['category_name']) : null;
        $price = round((float) ($row['price'] ?? 0), 2);
        $type = strtolower(trim((string) ($row['type'] ?? '')));
        $appItemId = trim((string) ($row['food99_code'] ?? '')) ?: (string) $productId;
        $published = in_array((string) ($row['food99_published'] ?? ''), ['1', 'true'], true);
        $hasRequiredModifiers = in_array((string) ($row['has_required_modifiers'] ?? ''), ['1', 'true'], true);
        $allowZeroPriceByGroups = $type === 'custom' && $hasRequiredModifiers;

        $blockers = [];
        if ($productName === '') {
            $blockers[] = 'Produto sem nome';
        }
        if (!$categoryId) {
            $blockers[] = 'Produto sem categoria';
        }
        if ($price <= 0 && !$allowZeroPriceByGroups) {
            $blockers[] = 'Produto com preco invalido';
        }

        return [
            'id' => $productId,
            'name' => $productName,
            'description' => trim((string) ($row['description'] ?? '')),
            'price' => $price,
            'type' => (string) ($row['type'] ?? ''),
            'category' => $categoryId ? [
                'id' => $categoryId,
                'name' => $categoryName,
            ] : null,
            'food99_code' => trim((string) ($row['food99_code'] ?? '')) ?: null,
            'food99_published' => $published,
            'has_required_modifiers' => $hasRequiredModifiers,
            'image_url' => $this->buildPublicFileDownloadUrl($row['cover_file_id'] ?? null),
            'suggested_app_item_id' => $appItemId,
            'eligible' => empty($blockers),
            'blockers' => $blockers,
        ];
    }

    public function listSelectableMenuProducts(People $provider): array
    {
        $this->init();

        $products = array_map(
            fn(array $row) => $this->buildMenuProductView($row),
            $this->fetchMenuProducts($provider)
        );

        return [
            'provider_id' => $provider->getId(),
            'minimum_required_items' => 5,
            'eligible_product_count' => count(array_filter($products, fn(array $product) => $product['eligible'])),
            'products' => $products,
        ];
    }

    private function resolvePublishedRemoteItemIds(?array $menuDetails): array
    {
        $items = is_array($menuDetails['data']['items'] ?? null) ? $menuDetails['data']['items'] : [];

        return array_values(array_unique(array_filter(array_map(
            static fn(array $item) => isset($item['app_item_id']) ? (string) $item['app_item_id'] : null,
            array_filter($items, 'is_array')
        ))));
    }

    private function resolveIncomingProductCode(array $item, string $productType): string
    {
        foreach (['app_item_id', 'mdu_id', 'app_external_id'] as $key) {
            $candidate = $this->normalizeIncomingFood99Value($item[$key] ?? null);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $fallbackSource = implode('|', array_filter([
            $productType,
            $this->normalizeIncomingFood99Value($item['name'] ?? null),
            $this->normalizeIncomingFood99Value($item['content_name'] ?? null),
            $this->normalizeIncomingFood99Value($item['app_content_id'] ?? null),
            $this->normalizeIncomingFood99Value($item['sku_price'] ?? null),
        ]));

        return 'food99:' . substr(sha1($fallbackSource !== '' ? $fallbackSource : json_encode($item)), 0, 24);
    }

    private function mapProductsWithRemoteCatalog(array $products, array $remoteItemIds): array
    {
        $remoteItemIdSet = array_flip($remoteItemIds);

        return array_map(function (array $product) use ($remoteItemIdSet) {
            $candidateId = (string) ($product['food99_code'] ?: $product['suggested_app_item_id'] ?: $product['id']);
            $product['published_remotely'] = isset($remoteItemIdSet[$candidateId]);

            return $product;
        }, $products);
    }

    private function resolveStatusLabel(?int $bizStatus): string
    {
        return match ($bizStatus) {
            1 => 'Online',
            2 => 'Offline',
            default => 'Indefinido',
        };
    }

    private function resolveSubStatusLabel(?int $subStatus): string
    {
        return match ($subStatus) {
            1 => 'Pronta',
            2 => 'Pausada',
            3 => 'Fechada',
            default => 'Indefinido',
        };
    }

    public function getIntegrationSnapshot(People $provider): array
    {
        $this->init();

        $products = $this->listSelectableMenuProducts($provider);
        $integratedStoreCode = $this->getIntegratedStoreCode($provider);
        $connected = !empty($integratedStoreCode);

        $detail = [
            'provider' => [
                'id' => $provider->getId(),
                'name' => method_exists($provider, 'getName') ? $provider->getName() : null,
            ],
            'integration' => [
                'key' => '99food',
                'label' => '99Food',
                'minimum_required_items' => 5,
                'eligible_product_count' => $products['eligible_product_count'] ?? 0,
                'connected' => $connected,
                'remote_connected' => false,
                'food99_code' => $integratedStoreCode,
                'app_shop_id' => (string) $provider->getId(),
                'auth_available' => false,
                'online' => false,
                'biz_status' => null,
                'biz_status_label' => 'Indefinido',
                'sub_biz_status' => null,
                'sub_biz_status_label' => 'Indefinido',
            ],
            'store' => null,
            'delivery_areas' => null,
            'menu' => [
                'remote_item_ids' => [],
            ],
            'products' => array_merge($products, [
                'published_product_count' => 0,
                'products' => $this->mapProductsWithRemoteCatalog($products['products'] ?? [], []),
            ]),
            'errors' => [],
        ];

        if (!$connected) {
            return $detail;
        }

        $authToken = $this->resolveIntegrationAccessToken($provider);
        $detail['integration']['auth_available'] = !empty($authToken);

        if (!$authToken) {
            $detail['errors']['auth'] = 'Nao foi possivel obter o auth_token da loja na 99Food.';
            return $detail;
        }

        try {
            $storeDetails = $this->getStoreDetails($provider);
            $detail['store'] = $storeDetails;

            $remoteConnected = is_array($storeDetails) && $this->isSuccessfulErrno($storeDetails['errno'] ?? null);
            $detail['integration']['remote_connected'] = $remoteConnected;

            $remoteStore = is_array($storeDetails['data'] ?? null) ? $storeDetails['data'] : null;
            $bizStatus = isset($remoteStore['biz_status']) ? (int) $remoteStore['biz_status'] : null;
            $subBizStatus = isset($remoteStore['sub_biz_status']) ? (int) $remoteStore['sub_biz_status'] : null;

            $detail['integration']['online'] = $remoteConnected && $bizStatus === 1;
            $detail['integration']['biz_status'] = $bizStatus;
            $detail['integration']['biz_status_label'] = $this->resolveStatusLabel($bizStatus);
            $detail['integration']['sub_biz_status'] = $subBizStatus;
            $detail['integration']['sub_biz_status_label'] = $this->resolveSubStatusLabel($subBizStatus);
        } catch (\Throwable $e) {
            $detail['errors']['store'] = $e->getMessage();
        }

        try {
            $deliveryAreas = $this->listDeliveryAreas($provider);
            $detail['delivery_areas'] = $deliveryAreas;
        } catch (\Throwable $e) {
            $detail['errors']['delivery_areas'] = $e->getMessage();
        }

        try {
            $menuDetails = $this->getStoreMenuDetails($provider);
            $remoteItemIds = $this->resolvePublishedRemoteItemIds($menuDetails);
            $mappedProducts = $this->mapProductsWithRemoteCatalog($products['products'] ?? [], $remoteItemIds);

            $detail['menu'] = array_merge(is_array($menuDetails) ? $menuDetails : [], [
                'remote_item_ids' => $remoteItemIds,
            ]);
            $detail['products'] = array_merge($products, [
                'products' => $mappedProducts,
                'published_product_count' => count(array_filter(
                    $mappedProducts,
                    static fn(array $product) => !empty($product['published_remotely'])
                )),
            ]);
        } catch (\Throwable $e) {
            $detail['errors']['menu'] = $e->getMessage();
        }

        return $detail;
    }

    public function syncIntegrationState(People $provider): array
    {
        $this->init();

        $sync = [
            'auth_available' => false,
            'store' => null,
            'delivery_areas' => null,
            'menu' => null,
            'errors' => [],
        ];

        $authToken = $this->resolveIntegrationAccessToken($provider);
        $sync['auth_available'] = !empty($authToken);

        if (!$authToken) {
            $message = 'Nao foi possivel obter o auth_token da loja na 99Food.';
            $this->persistIntegrationAuthError($provider, $message);
            $sync['errors']['auth'] = $message;
            return $sync;
        }

        $this->clearIntegrationError($provider);

        $storeDetails = $this->getStoreDetails($provider);
        $sync['store'] = $storeDetails;
        if (!$this->isSuccessfulErrno($storeDetails['errno'] ?? null)) {
            $sync['errors']['store'] = $storeDetails['errmsg'] ?? 'Nao foi possivel sincronizar os detalhes da loja.';
        }

        $deliveryAreas = $this->listDeliveryAreas($provider);
        $sync['delivery_areas'] = $deliveryAreas;
        if (!$this->isSuccessfulErrno($deliveryAreas['errno'] ?? null)) {
            $sync['errors']['delivery_areas'] = $deliveryAreas['errmsg'] ?? 'Nao foi possivel sincronizar as areas de entrega.';
        }

        $menuDetails = $this->getStoreMenuDetails($provider);
        $sync['menu'] = $menuDetails;
        if (!$this->isSuccessfulErrno($menuDetails['errno'] ?? null)) {
            $sync['errors']['menu'] = $menuDetails['errmsg'] ?? 'Nao foi possivel sincronizar o menu remoto.';
        }

        return $sync;
    }

    public function buildStoreMenuPayloadFromProducts(People $provider, array $productIds): array
    {
        $this->init();

        $selectedIds = $this->normalizeProductIds($productIds);
        if (empty($selectedIds)) {
            return [
                'provider_id' => $provider->getId(),
                'selected_product_count' => 0,
                'eligible_product_count' => 0,
                'errors' => ['Nenhum produto foi selecionado para a integracao.'],
                'products' => [],
                'payload' => null,
            ];
        }

        $rows = $this->fetchMenuProducts($provider, $selectedIds);
        $products = array_map(fn(array $row) => $this->buildMenuProductView($row), $rows);
        $resolvedIds = array_map(fn(array $product) => (int) $product['id'], $products);

        $errors = [];
        if (count($resolvedIds) !== count($selectedIds)) {
            $missingIds = array_values(array_diff($selectedIds, $resolvedIds));
            $errors[] = 'Um ou mais produtos selecionados nao pertencem a empresa atual ou nao estao ativos.';
            if (!empty($missingIds)) {
                self::$logger->warning('Food99 menu builder skipped missing products', [
                    'provider_id' => $provider->getId(),
                    'missing_product_ids' => $missingIds,
                ]);
            }
        }

        $ineligibleProducts = array_values(array_filter($products, fn(array $product) => !$product['eligible']));
        if (!empty($ineligibleProducts)) {
            $errors[] = 'Existem produtos selecionados sem categoria ou com preco invalido.';
        }

        $eligibleProducts = array_values(array_filter($products, fn(array $product) => $product['eligible']));
        if (count($eligibleProducts) < 5) {
            $errors[] = 'A 99Food exige pelo menos 5 produtos unicos elegiveis para publicar o menu.';
        }

        if (!empty($errors)) {
            return [
                'provider_id' => $provider->getId(),
                'selected_product_count' => count($products),
                'eligible_product_count' => count($eligibleProducts),
                'errors' => $errors,
                'products' => $products,
                'payload' => null,
            ];
        }

        $categoriesMap = [];
        $itemsById = [];
        $parentItemIdByProductId = [];
        $nextPriority = 1;

        $upsertItem = static function (array &$itemsById, string $appItemId, array $itemData) use (&$nextPriority): void {
            $itemData['app_item_id'] = $appItemId;

            if (!isset($itemsById[$appItemId])) {
                $itemData['priority'] = $itemData['priority'] ?? $nextPriority++;
                $itemData['status'] = $itemData['status'] ?? 1;
                $itemData['short_desc'] = $itemData['short_desc'] ?? '';
                $itemData['is_sold_separately'] = $itemData['is_sold_separately'] ?? true;
                $itemsById[$appItemId] = $itemData;
                return;
            }

            $existingItem = $itemsById[$appItemId];

            if (
                !empty($itemData['item_name'])
                && (!isset($existingItem['item_name']) || trim((string) $existingItem['item_name']) === '')
            ) {
                $existingItem['item_name'] = $itemData['item_name'];
            }

            if (
                array_key_exists('price', $itemData)
                && (!isset($existingItem['price']) || (int) $existingItem['price'] === 0)
            ) {
                $existingItem['price'] = (int) $itemData['price'];
            }

            if (!empty($itemData['short_desc']) && empty($existingItem['short_desc'])) {
                $existingItem['short_desc'] = $itemData['short_desc'];
            }

            if (!empty($itemData['head_img']) && empty($existingItem['head_img'])) {
                $existingItem['head_img'] = $itemData['head_img'];
            }

            if (($itemData['is_sold_separately'] ?? false) === true) {
                $existingItem['is_sold_separately'] = true;
            }

            $itemsById[$appItemId] = $existingItem;
        };

        foreach ($eligibleProducts as $index => $product) {
            $categoryId = (string) $product['category']['id'];
            if (!isset($categoriesMap[$categoryId])) {
                $categoriesMap[$categoryId] = [
                    'app_category_id' => $categoryId,
                    'category_name' => $product['category']['name'],
                    'app_item_ids' => [],
                    'priority' => count($categoriesMap) + 1,
                ];
            }

            $appItemId = (string) $product['suggested_app_item_id'];
            $parentItemIdByProductId[(int) $product['id']] = $appItemId;
            $categoriesMap[$categoryId]['app_item_ids'][] = $appItemId;

            $itemPayload = [
                'item_name' => $product['name'],
                'short_desc' => $product['description'],
                'price' => (int) round($product['price'] * 100),
                'priority' => $index + 1,
                'is_sold_separately' => true,
            ];
            if (!empty($product['image_url'])) {
                $itemPayload['head_img'] = (string) $product['image_url'];
            }
            $upsertItem($itemsById, $appItemId, $itemPayload);
        }

        foreach ($categoriesMap as &$category) {
            $category['app_item_ids'] = array_values(array_unique($category['app_item_ids']));
        }
        unset($category);

        $modifierRows = $this->fetchMenuModifierRows($provider, array_keys($parentItemIdByProductId));
        $modifierGroupsMap = [];

        foreach ($modifierRows as $modifierRow) {
            $parentProductId = (int) ($modifierRow['parent_product_id'] ?? 0);
            if ($parentProductId <= 0 || !isset($parentItemIdByProductId[$parentProductId])) {
                continue;
            }

            $groupId = (int) ($modifierRow['product_group_id'] ?? 0);
            if ($groupId <= 0) {
                continue;
            }

            $appModifierGroupId = 'mg_' . $groupId;
            if (!isset($modifierGroupsMap[$appModifierGroupId])) {
                $isRequired = (int) ($modifierRow['group_required'] ?? 0) === 1;
                $minimum = max(0, (int) round((float) ($modifierRow['group_minimum'] ?? 0)));
                $maximum = max(0, (int) round((float) ($modifierRow['group_maximum'] ?? 0)));

                if ($isRequired && $minimum === 0) {
                    $minimum = 1;
                }

                $modifierGroupsMap[$appModifierGroupId] = [
                    'app_modifier_group_id' => $appModifierGroupId,
                    'modifier_group_name' => trim((string) ($modifierRow['product_group_name'] ?? 'Grupo')),
                    'is_required' => $isRequired ? 1 : 2,
                    'quantity_min_permitted' => $minimum,
                    'quantity_max_permitted' => $maximum,
                    'buy_mode' => 0,
                    'app_mg_items' => [],
                    '_parent_item_ids' => [],
                ];
            }

            $parentAppItemId = $parentItemIdByProductId[$parentProductId];
            if (!in_array($parentAppItemId, $modifierGroupsMap[$appModifierGroupId]['_parent_item_ids'], true)) {
                $modifierGroupsMap[$appModifierGroupId]['_parent_item_ids'][] = $parentAppItemId;
            }

            $childProductId = (int) ($modifierRow['child_product_id'] ?? 0);
            $childName = trim((string) ($modifierRow['child_product_name'] ?? ''));
            if ($childProductId <= 0 || $childName === '') {
                continue;
            }

            $childAppItemId = trim((string) ($modifierRow['child_food99_code'] ?? ''));
            if ($childAppItemId === '') {
                $childAppItemId = (string) $childProductId;
            }

            $rawChildPrice = $modifierRow['child_relation_price'] ?? null;
            $childPrice = ($rawChildPrice === null || $rawChildPrice === '')
                ? (float) ($modifierRow['child_base_price'] ?? 0)
                : (float) $rawChildPrice;

            $childPriceCents = max(0, (int) round($childPrice * 100));
            $childImageUrl = $this->buildPublicFileDownloadUrl($modifierRow['child_cover_file_id'] ?? null);

            $childPayload = [
                'item_name' => $childName,
                'short_desc' => trim((string) ($modifierRow['child_description'] ?? '')),
                'price' => $childPriceCents,
                'is_sold_separately' => false,
            ];
            if ($childImageUrl) {
                $childPayload['head_img'] = $childImageUrl;
            }
            $upsertItem($itemsById, $childAppItemId, $childPayload);

            $modifierGroupsMap[$appModifierGroupId]['app_mg_items'][] = [
                'app_item_id' => $childAppItemId,
                'price' => $childPriceCents,
            ];
        }

        $modifierGroups = [];
        foreach ($modifierGroupsMap as $modifierGroup) {
            $deduplicatedModifierItems = [];
            foreach ($modifierGroup['app_mg_items'] as $modifierItem) {
                $modifierItemId = (string) ($modifierItem['app_item_id'] ?? '');
                if ($modifierItemId === '' || isset($deduplicatedModifierItems[$modifierItemId])) {
                    continue;
                }

                $deduplicatedModifierItems[$modifierItemId] = [
                    'app_item_id' => $modifierItemId,
                    'price' => max(0, (int) ($modifierItem['price'] ?? 0)),
                ];
            }

            if (empty($deduplicatedModifierItems)) {
                continue;
            }

            $modifierGroup['app_mg_items'] = array_values($deduplicatedModifierItems);
            $modifierItemCount = count($modifierGroup['app_mg_items']);

            if (($modifierGroup['quantity_max_permitted'] ?? 0) <= 0) {
                $modifierGroup['quantity_max_permitted'] = $modifierItemCount;
            }

            if (($modifierGroup['quantity_max_permitted'] ?? 0) < ($modifierGroup['quantity_min_permitted'] ?? 0)) {
                $modifierGroup['quantity_max_permitted'] = $modifierGroup['quantity_min_permitted'];
            }

            foreach ($modifierGroup['_parent_item_ids'] as $parentItemId) {
                if (!isset($itemsById[$parentItemId])) {
                    continue;
                }

                if (!isset($itemsById[$parentItemId]['app_modifier_group_ids'])) {
                    $itemsById[$parentItemId]['app_modifier_group_ids'] = [];
                }

                if (!in_array($modifierGroup['app_modifier_group_id'], $itemsById[$parentItemId]['app_modifier_group_ids'], true)) {
                    $itemsById[$parentItemId]['app_modifier_group_ids'][] = $modifierGroup['app_modifier_group_id'];
                }
            }

            unset($modifierGroup['_parent_item_ids']);
            $modifierGroups[] = $modifierGroup;
        }

        $payload = [
            'menus' => [[
                'app_menu_id' => 'menu_' . $provider->getId() . '_principal',
                'menu_name' => 'Cardapio Principal',
                'app_category_ids' => array_values(array_map(
                    static fn(string|int $categoryId) => (string) $categoryId,
                    array_keys($categoriesMap)
                )),
            ]],
            'categories' => array_values($categoriesMap),
            'items' => array_values($itemsById),
            'modifier_groups' => $modifierGroups,
        ];

        return [
            'provider_id' => $provider->getId(),
            'selected_product_count' => count($products),
            'eligible_product_count' => count($eligibleProducts),
            'errors' => [],
            'products' => $products,
            'payload' => $payload,
        ];
    }

    public function ensureMenuProductCodes(People $provider, array $productIds): array
    {
        $this->init();

        $selectedIds = $this->normalizeProductIds($productIds);
        if (empty($selectedIds)) {
            return [];
        }

        $products = $this->entityManager->getRepository(Product::class)->findBy([
            'id' => $selectedIds,
        ]);

        $codes = [];
        foreach ($products as $product) {
            $code = $this->findLocalFoodCodeByEntity('Product', (int) $product->getId());
            if (!$code) {
                $code = (string) $product->getId();
                $persistedCode = $this->persistLocalFoodCodeByEntity('Product', (int) $product->getId(), $code);
                $code = $persistedCode ?: $code;
            }

            $codes[$product->getId()] = (string) $code;
        }

        self::$logger->info('Food99 product codes ensured for menu upload', [
            'provider_id' => $provider->getId(),
            'product_ids' => array_keys($codes),
        ]);

        return $codes;
    }

    private function getModifierGroupItemIds(array $modifierGroups): array
    {
        $modifierItemIds = [];

        foreach ($modifierGroups as $modifierGroup) {
            if (!is_array($modifierGroup)) {
                continue;
            }

            foreach (($modifierGroup['app_mg_items'] ?? []) as $modifierItem) {
                if (!is_array($modifierItem) || empty($modifierItem['app_item_id'])) {
                    continue;
                }

                $modifierItemIds[] = (string) $modifierItem['app_item_id'];
            }

            foreach (($modifierGroup['app_mg_item_ids'] ?? []) as $modifierItemId) {
                if ($modifierItemId === null || $modifierItemId === '') {
                    continue;
                }

                $modifierItemIds[] = (string) $modifierItemId;
            }
        }

        return array_values(array_unique($modifierItemIds));
    }

    private function isActiveMenuItem(array $item): bool
    {
        $status = $item['status'] ?? null;

        return $status === null || (string) $status === '1';
    }

    private function isStandaloneMenuItem(array $item): bool
    {
        $isSoldSeparately = $item['is_sold_separately'] ?? null;

        if ($isSoldSeparately === null) {
            return true;
        }

        return in_array($isSoldSeparately, [true, 1, '1', 'true'], true);
    }

    private function getUniqueSellableMenuItemIds(array $payload): array
    {
        $sellableItemIds = [];

        foreach (($payload['items'] ?? []) as $item) {
            if (!is_array($item) || empty($item['app_item_id'])) {
                continue;
            }

            $itemId = (string) $item['app_item_id'];

            if (!$this->isStandaloneMenuItem($item) || !$this->isActiveMenuItem($item)) {
                continue;
            }

            $sellableItemIds[] = $itemId;
        }

        return array_values(array_unique($sellableItemIds));
    }

    private function validateStoreMenuPayload(People $provider, array $payload): ?array
    {
        if (!isset($payload['menus'], $payload['categories'], $payload['items'])) {
            self::$logger->warning('Food99 uploadStoreMenu called without required menu sections', [
                'provider_id' => $provider->getId(),
                'payload_keys' => array_keys($payload),
            ]);

            return null;
        }

        $sellableItemIds = $this->getUniqueSellableMenuItemIds($payload);
        $sellableItemCount = count($sellableItemIds);

        if ($sellableItemCount >= 5) {
            return null;
        }

        self::$logger->warning('Food99 menu upload blocked because the menu has fewer than 5 unique sellable items', [
            'provider_id' => $provider->getId(),
            'sellable_item_count' => $sellableItemCount,
            'sellable_item_ids' => $sellableItemIds,
        ]);

        return [
            'errno' => 10002,
            'errmsg' => 'Food99 requires at least 5 unique sellable items before publishing a store menu.',
            'data' => [
                'sellable_item_count' => $sellableItemCount,
                'sellable_item_ids' => $sellableItemIds,
            ],
        ];
    }

    private function isHttpImageUrl(?string $url): bool
    {
        if (!$url) {
            return false;
        }

        return (bool) preg_match('/^https?:\\/\\//i', trim($url));
    }

    private function buildFood99ImageUploadExt(People $provider, string $appItemId, string $sourceUrl): string
    {
        $base = sprintf('%s-%s', (string) $provider->getId(), $appItemId !== '' ? $appItemId : md5($sourceUrl));
        $base = trim($base);

        if ($base === '') {
            $base = md5($sourceUrl);
        }

        if (function_exists('mb_substr')) {
            return mb_substr($base, 0, 255);
        }

        return substr($base, 0, 255);
    }

    private function syncStoreMenuHeadImagesToFood99(People $provider, array &$payload): array
    {
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        if (empty($items)) {
            return [
                'uploaded' => 0,
                'reused' => 0,
                'failed' => 0,
                'skipped' => 0,
            ];
        }

        $uploadedBySourceUrl = [];
        $failedSourceUrls = [];
        $stats = [
            'uploaded' => 0,
            'reused' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $stats['skipped']++;
                continue;
            }

            $sourceUrl = trim((string) ($item['head_img'] ?? ''));
            if (!$this->isHttpImageUrl($sourceUrl)) {
                $stats['skipped']++;
                continue;
            }

            if (isset($uploadedBySourceUrl[$sourceUrl])) {
                $payload['items'][$index]['head_img'] = $uploadedBySourceUrl[$sourceUrl];
                $stats['reused']++;
                continue;
            }

            if (isset($failedSourceUrls[$sourceUrl])) {
                unset($payload['items'][$index]['head_img']);
                $stats['failed']++;
                continue;
            }

            $appItemId = trim((string) ($item['app_item_id'] ?? ''));
            $normalizedImagePath = $this->normalizeImageForFood99Upload($sourceUrl, (int) $provider->getId(), $appItemId);
            $uploadPayload = [
                'ext' => $this->buildFood99ImageUploadExt($provider, $appItemId, $sourceUrl),
            ];

            if ($normalizedImagePath) {
                $uploadPayload['image_file'] = DataPart::fromPath($normalizedImagePath, basename($normalizedImagePath), 'image/jpeg');
            } else {
                $uploadPayload['image_url'] = $sourceUrl;
            }

            $uploadResponse = $this->uploadImage($provider, $uploadPayload);

            $uploadedUrl = trim((string) ($uploadResponse['data']['giftUrl'] ?? ''));
            if ($this->isSuccessfulErrno($uploadResponse['errno'] ?? null) && $uploadedUrl !== '') {
                $uploadedBySourceUrl[$sourceUrl] = $uploadedUrl;
                $payload['items'][$index]['head_img'] = $uploadedUrl;
                $stats['uploaded']++;
                if ($normalizedImagePath && file_exists($normalizedImagePath)) {
                    @unlink($normalizedImagePath);
                }
                continue;
            }

            if ($normalizedImagePath && file_exists($normalizedImagePath)) {
                @unlink($normalizedImagePath);
            }

            $failedSourceUrls[$sourceUrl] = true;
            $stats['failed']++;
            unset($payload['items'][$index]['head_img']);

            self::$logger->warning('Food99 image upload falhou; head_img removido do payload para evitar URL inacessivel', [
                'provider_id' => $provider->getId(),
                'app_item_id' => $appItemId,
                'image_url' => $sourceUrl,
                'errno' => $uploadResponse['errno'] ?? null,
                'errmsg' => $uploadResponse['errmsg'] ?? null,
            ]);
        }

        return $stats;
    }

    /**
     * Converte bytes brutos de imagem para recurso GD.
     * Tenta GD nativo primeiro; para formatos não suportados (SVG, AVIF, etc.)
     * tenta Imagick se disponível, convertendo internamente para JPEG antes.
     */
    private function tryRawToGdImage(string $raw): ?\GdImage
    {
        $image = @imagecreatefromstring($raw);
        if ($image instanceof \GdImage) {
            return $image;
        }

        if (!class_exists('Imagick')) {
            return null;
        }

        try {
            $imagick = new \Imagick();
            $imagick->setResolution(150, 150);
            $imagick->readImageBlob($raw);
            $imagick->setImageBackgroundColor(new \ImagickPixel('white'));
            $imagick = $imagick->flattenImages();
            $imagick->setImageFormat('jpeg');
            $jpeg = $imagick->getImageBlob();
            $imagick->clear();
            $result = @imagecreatefromstring($jpeg);
            return ($result instanceof \GdImage) ? $result : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeImageForFood99Upload(string $sourceUrl, int $providerId, string $appItemId): ?string
    {
        try {
            if (!$this->isHttpImageUrl($sourceUrl) || !function_exists('imagecreatefromstring')) {
                return null;
            }

            $response = $this->httpClient->request('GET', $sourceUrl);
            if ($response->getStatusCode() >= 400) {
                return null;
            }

            $raw = $response->getContent(false);
            if (!$raw) {
                return null;
            }

            $image = $this->tryRawToGdImage($raw);
            if (!$image) {
                return null;
            }

            $width = imagesx($image);
            $height = imagesy($image);

            if ($width <= 0 || $height <= 0) {
                imagedestroy($image);
                return null;
            }

            $maxDimension = 3000;
            $minDimension = 150;
            $targetWidth = $width;
            $targetHeight = $height;

            if ($targetWidth > $maxDimension || $targetHeight > $maxDimension) {
                $scale = min($maxDimension / $targetWidth, $maxDimension / $targetHeight);
                $targetWidth = (int) max(1, floor($targetWidth * $scale));
                $targetHeight = (int) max(1, floor($targetHeight * $scale));
            }

            if ($targetWidth < $minDimension || $targetHeight < $minDimension) {
                $scale = max($minDimension / max(1, $targetWidth), $minDimension / max(1, $targetHeight));
                $targetWidth = (int) max($minDimension, ceil($targetWidth * $scale));
                $targetHeight = (int) max($minDimension, ceil($targetHeight * $scale));
            }

            $normalized = imagecreatetruecolor($targetWidth, $targetHeight);
            imagealphablending($normalized, true);
            imagesavealpha($normalized, false);
            $white = imagecolorallocate($normalized, 255, 255, 255);
            imagefilledrectangle($normalized, 0, 0, $targetWidth, $targetHeight, $white);
            imagecopyresampled($normalized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
            imagedestroy($image);

            $tempBase = tempnam(sys_get_temp_dir(), 'food99_img_');
            if (!$tempBase) {
                imagedestroy($normalized);
                return null;
            }

            $targetPath = $tempBase . '.jpg';
            @unlink($tempBase);

            $quality = 90;
            $saved = false;

            while ($quality >= 70) {
                $saved = imagejpeg($normalized, $targetPath, $quality);
                if ($saved && file_exists($targetPath) && filesize($targetPath) <= 10 * 1024 * 1024) {
                    break;
                }
                $quality -= 5;
            }

            imagedestroy($normalized);

            if (!$saved || !file_exists($targetPath)) {
                if (file_exists($targetPath)) {
                    @unlink($targetPath);
                }
                return null;
            }

            if (filesize($targetPath) > 10 * 1024 * 1024) {
                @unlink($targetPath);
                return null;
            }

            return $targetPath;
        } catch (\Throwable $e) {
            self::$logger->warning('Food99 image normalization failed before upload', [
                'provider_id' => $providerId,
                'app_item_id' => $appItemId,
                'image_url' => $sourceUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function uploadStoreMenu(People $provider, array $payload): ?array
    {
        $this->init();

        if (!isset($payload['modifier_groups'])) {
            $payload['modifier_groups'] = [];
        }

        $validationError = $this->validateStoreMenuPayload($provider, $payload);
        if ($validationError) {
            return $validationError;
        }

        $imageSyncStats = $this->syncStoreMenuHeadImagesToFood99($provider, $payload);

        $response = $this->call99EndpointWithResponse('/v3/item/item/upload', $payload, $provider);
        $taskId = is_array($response['data'] ?? null) ? ($response['data']['taskID'] ?? null) : null;
        $response = is_array($response) ? $this->normalizeMenuTaskResponse($response, $taskId) : $response;

        if (is_array($response)) {
            $response['image_sync'] = $imageSyncStats;
        }

        if ($this->isSuccessfulErrno($response['errno'] ?? null)) {
            $this->persistProviderMenuUploadSubmission($provider, $response, $taskId);
            return $response;
        }

        $this->persistProviderLastError($provider, $response['errno'] ?? null, $response['errmsg'] ?? null);

        return $response;
    }

    public function getMenuUploadTaskInfo(People $provider, int|string $taskId): ?array
    {
        $this->init();

        $response = $this->call99EndpointWithResponse('/v1/item/item/getMenuTaskInfo', [
            'task_id' => $taskId,
        ], $provider);
        $response = is_array($response) ? $this->normalizeMenuTaskResponse($response, $taskId) : $response;

        if (!$this->isSuccessfulErrno($response['errno'] ?? null)) {
            $this->persistProviderLastError($provider, $response['errno'] ?? null, $response['errmsg'] ?? null);
            return $response;
        }

        $taskState = $this->persistProviderMenuTaskState($provider, $response, $taskId);

        if ($taskState === 'completed') {
            $menuResponse = $this->getStoreMenuDetails($provider);
            if ($this->isSuccessfulErrno($menuResponse['errno'] ?? null)) {
                $this->markProviderMenuPublished($provider);
            } else {
                $this->markProviderMenuSyncError($provider, $menuResponse['errmsg'] ?? null);
            }
        }

        return $response;
    }


    public function integrate(Integration $integration): ?Order
    {
        $this->init();

        self::$logger->info('Food99 RAW BODY', [
            'integration_id' => $integration->getId(),
            'body' => $integration->getBody()
        ]);

        $json = json_decode($integration->getBody(), true);

        $data  = is_array($json) ? ($json['data'] ?? []) : [];
        $info  = is_array($data) ? ($data['order_info'] ?? []) : [];
        $items = is_array($info) ? ($info['order_items'] ?? null) : null;

        self::$logger->info('Food99 JSON DECODE', [
            'json_error' => json_last_error_msg(),
            'has_type' => is_array($json) && isset($json['type']),
            'has_data' => is_array($json) && isset($json['data']),
            'has_order_info' => is_array($data) && isset($data['order_info']),
            'has_order_items' => isset($items) || (is_array($data) && isset($data['order_items'])),
            'order_items_path' => isset($items) ? 'data.order_info.order_items' : (isset($data['order_items']) ? 'data.order_items' : null),
            'order_items_type' => gettype($items ?? ($data['order_items'] ?? null)),
            'context' => $this->buildLogContext($integration, is_array($json) ? $json : []),
        ]);

        if (!is_array($json)) {
            self::$logger->warning('Food99 payload is not a valid JSON object', [
                'integration_id' => $integration->getId(),
            ]);
            return null;
        }

        $this->syncProviderWebhookReceiptState($json);

        $rawEventType = $this->normalizeIncomingFood99Value($json['type'] ?? null);
        $eventType = strtolower($rawEventType);

        if ($eventType === 'shopstatus') {
            $this->syncStoreStatusWebhook($json);
            return null;
        }

        return match ($eventType) {
            'ordernew' => $this->addOrder($json),
            'ordercancel' => $this->handleOrderCancelEvent($json),
            'orderfinish' => $this->handleOrderFinishEvent($json),
            'deliverystatus' => $this->handleDeliveryStatusEvent($json),
            default => $this->handleFallbackOrderEvent($integration, $json),
        };
    }

    private function addOrder(array $json): ?Order
    {
        $data = $json['data'] ?? [];
        if (!is_array($data)) {
            $data = [];
        }

        $info = $data['order_info'] ?? [];
        if (!is_array($info)) {
            $info = [];
        }

        $shop  = $info['shop']  ?? ($data['shop']  ?? []);
        $price = $info['price'] ?? ($data['price'] ?? []);

        if (!is_array($shop))  $shop = [];
        if (!is_array($price)) $price = [];

        $items = $info['order_items'] ?? ($data['order_items'] ?? []);
        if (!is_array($items)) {
            $items = [];
        }

        $receiveAddress = $info['receive_address'] ?? ($data['receive_address'] ?? []);
        if (!is_array($receiveAddress)) {
            $receiveAddress = [];
        }

        self::$logger->info('Food99 ADD ORDER DATA', $this->buildLogContext(null, $json, [
            'keys' => array_keys($data),
            'has_order_info' => !empty($info),
            'order_items_type' => gettype($items),
            'order_items_count' => is_array($items) ? count($items) : null,
        ]));

        $identifiers = $this->extractIncomingOrderIdentifiers($json);
        $orderId = $identifiers['order_id'];
        $orderIndex = $identifiers['order_index'];
        $orderCode = $identifiers['order_code'];

        if ($orderId === '') {
            self::$logger->error('Food99 order ignored because order_id is missing', $this->buildLogContext(null, $json));
            return null;
        }

        $lockAcquired = $this->acquireOrderIntegrationLock($orderId);
        $allowCodeFallback = ($orderId === '');
        if (!$lockAcquired) {
            $existing = $this->waitForExistingIntegratedOrder(
                $orderId,
                $orderCode,
                5,
                250000,
                $allowCodeFallback
            );
            if ($existing instanceof Order) {
                self::$logger->info('Food99 duplicate webhook resolved after waiting for in-flight order lock owner', $this->buildLogContext(null, $json, [
                    'local_order_id' => $existing->getId(),
                    'order_code' => $orderCode,
                ]));
                return $existing;
            }

            self::$logger->warning('Food99 order integration lock unavailable; continuing with duplicate checks only', $this->buildLogContext(null, $json, [
                'order_code' => $orderCode,
                'lock_required' => false,
            ]));
        }

        try {
            $exists = $this->findExistingIntegratedOrder($orderId, $orderCode, $allowCodeFallback);
            if ($exists instanceof Order) {
                self::$logger->info('Food99 order already integrated, skipping duplicate creation', $this->buildLogContext(null, $json, [
                    'local_order_id' => $exists->getId(),
                    'dedupe_by' => $orderId !== '' ? 'order_id' : 'order_code',
                ]));
                return $exists;
            }

            $shopId = $this->normalizeIncomingFood99Value($shop['shop_id'] ?? null);

            $provider = null;
            if ($shopId !== '') {
                $provider = $this->findFood99EntityByExtraData('People', 'code', $shopId, People::class);
            }

            if (!$provider) {
                $provider = $this->peopleService->discoveryPeople(
                    null,
                    null,
                    null,
                    $shop['shop_name'] ?? 'Loja Food99',
                    'J'
                );
                if ($shopId !== '') {
                    $this->persistLocalFoodCodeByEntity('People', (int) $provider->getId(), $shopId);
                }
            }

            $client = $this->resolveOrderClient($provider, $receiveAddress, $orderId);
            $status = $this->statusService->discoveryStatus('open', 'open', 'order');
            $orderPrice = isset($price['order_price']) ? ((float) $price['order_price']) / 100 : 0.0;

            $order = $this->createOrder($client, $provider, $orderPrice, $status, $json);
            $this->syncOrderComments($order, $this->extractOrderRemark($json));
            $this->entityManager->persist($order);
            $this->entityManager->flush();

            $this->persistLocalFoodIdByEntity('Order', (int) $order->getId(), $orderId);
            $this->persistLocalFoodCodeByEntity('Order', (int) $order->getId(), $orderCode);
            $this->persistOrderIntegrationState($order, array_merge([
                'last_event_type' => 'orderNew',
                'last_event_at' => $this->extractOrderEventTimestamp($json),
                'remote_order_state' => 'new',
                'remote_delivery_status' => $this->extractOrderDeliveryStatus($json),
            ], $this->extractOrderDeliveryStateFields($json)));

            self::$logger->info('Food99 order shell persisted locally before item/address processing', $this->buildLogContext(null, $json, [
                'provider_id' => $provider?->getId(),
                'client_id' => $client?->getId(),
                'local_order_id' => $order->getId(),
                'order_code' => $orderCode,
            ]));

            self::$logger->info('Food99 BEFORE addProducts', $this->buildLogContext(null, $json, [
                'is_array' => is_array($items),
                'count' => is_array($items) ? count($items) : null,
                'value_preview' => is_array($items) ? array_slice($items, 0, 2) : null
            ]));

            if (!empty($items)) {
                $this->addProducts($order, $items);
            } else {
                self::$logger->error('Food99 order_items inválido', [
                    'order_id' => $orderId,
                    'order_items' => $items
                ]);
            }

            $this->addAddress($order, $receiveAddress);

            $this->entityManager->persist($order);
            $this->entityManager->flush();

            self::$logger->info('Food99 order persisted locally', $this->buildLogContext(null, $json, [
                'provider_id' => $provider?->getId(),
                'client_id' => $client?->getId(),
                'local_order_id' => $order->getId(),
            ]));

            $this->addPayments($order, $data);

            $this->confirmOrder($order, $orderId, $provider);
            $this->printOrder($order);

            return $order;
        } finally {
            if ($lockAcquired) {
                $this->releaseOrderIntegrationLock($orderId);
            }
        }
    }

    private function confirmOrder(Order $order, string $orderId, ?People $provider = null): array
    {
        $url = $this->getFood99BaseUrl() . '/v1/order/order/confirm';
        $accessToken = $this->resolveAccessToken($provider);

        if (!$accessToken) {
            $message = 'Token de acesso indisponivel para confirmar pedido na 99Food.';
            self::$logger->warning('Food99 confirm skipped because access token is unavailable', [
                'order_id' => $orderId,
                'provider_id' => $provider?->getId(),
                'api_base_url' => $this->getFood99BaseUrl(),
                'message' => $message,
            ]);

            return $this->persistOrderConfirmResult(
                $order,
                $this->buildUnavailableOrderActionResponse($message)
            );
        }

        $payload = [
            'auth_token' => $accessToken,
            'order_id' => $orderId,
        ];

        $startedAt = microtime(true);

        try {
            self::$logger->info('Food99 ORDER CONFIRM REQUEST', [
                'order_id' => $orderId,
                'payload' => $this->sanitizePayloadForLog($payload),
                'provider_id' => $provider?->getId(),
                'url' => $url,
            ]);

            $response = $this->httpClient->request('POST', $url, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $result = $response->toArray(false);

            self::$logger->info('Food99 ORDER CONFIRM RESPONSE', [
                'order_id' => $orderId,
                'status_code' => $response->getStatusCode(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'response' => $result,
            ]);

            return $this->persistOrderConfirmResult($order, is_array($result) ? $result : null);
        } catch (\Throwable $e) {
            $errorCode = (int) $e->getCode();
            if ($errorCode === 0) {
                $errorCode = 10002;
            }

            self::$logger->error('Food99 ORDER CONFIRM ERROR', [
                'order_id' => $orderId,
                'payload' => $this->sanitizePayloadForLog($payload),
                'error_code' => $errorCode,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $e->getMessage(),
            ]);

            return $this->persistOrderConfirmResult($order, [
                'errno' => $errorCode,
                'errmsg' => $e->getMessage(),
                'data' => [],
            ]);
        }
    }

    /**
     * Cria invoices para o pedido 99Food seguindo o mesmo padrão do iFood:
     * 1. Invoice de recebimento (valor pago pelo cliente)
     * 2. Invoice de taxa da plataforma (diferença entre valor do cliente e repasse ao restaurante)
     * 3. Invoice de taxa de entrega (apenas quando a 99 plataforma realiza a entrega)
     *
     * Qualquer falha aqui é logada mas não reverte o pedido já persistido.
     */
    private function addPayments(Order $order, array $data): void
    {
        try {
            $info = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];
            $price = is_array($info['price'] ?? null)
                ? $info['price']
                : (is_array($data['price'] ?? null) ? $data['price'] : []);
            $otherFees = is_array($price['others_fees'] ?? null) ? $price['others_fees'] : [];

            $deliveryType = $this->normalizeIncomingFood99Value($info['delivery_type'] ?? $data['delivery_type'] ?? null);
            $isPlatformDelivery = $deliveryType === '1';

            $itemsTotal = $this->normalizeFood99Money($price['order_price'] ?? null);
            $deliveryFee = $this->normalizeFood99Money($price['store_charged_delivery_price'] ?? $price['delivery_price'] ?? null);
            $serviceFee = $this->normalizeFood99Money($otherFees['service_price'] ?? null);
            $smallOrderFee = $this->normalizeFood99Money($otherFees['small_order_price'] ?? null);
            $tipTotal = $this->normalizeFood99Money($otherFees['total_tip_money'] ?? null);
            $mealTopUpFee = $this->normalizeFood99Money($otherFees['meal_top_up_price'] ?? null);
            $subtotalBeforeDiscounts = round($itemsTotal + $deliveryFee + $serviceFee + $smallOrderFee + $tipTotal + $mealTopUpFee, 2);

            $explicitCustomerTotal = $this->normalizeFood99Money(
                $price['customer_need_paying_money'] ?? $price['real_pay_price'] ?? $price['real_price'] ?? null
            );
            $customerTotal = $explicitCustomerTotal > 0 ? $explicitCustomerTotal : $subtotalBeforeDiscounts;
            $shopPaidMoney = $this->normalizeFood99Money($price['shop_paid_money'] ?? null);

            $wallet = $this->walletService->discoverWallet($order->getProvider(), self::$app);
            $paidStatus = $this->statusService->discoveryStatus('closed', 'paid', 'invoice');

            self::$logger->info('Food99 addPayments', [
                'order_id' => $order->getId(),
                'customer_total' => $customerTotal,
                'shop_paid_money' => $shopPaidMoney,
                'delivery_fee' => $deliveryFee,
                'is_platform_delivery' => $isPlatformDelivery,
                'is_paid_online' => $isPlatformDelivery,
            ]);

            // 1. Invoice de recebimento: valor pago pelo cliente ao restaurante
            if ($customerTotal > 0) {
                $this->invoiceService->createInvoiceByOrder(
                    $order,
                    $customerTotal,
                    $isPlatformDelivery ? $paidStatus : null,
                    new DateTime(),
                    null,
                    $wallet
                );
            }

            // 2. Invoice de taxa da plataforma: diferença entre o que o cliente pagou e o repasse ao restaurante
            if ($shopPaidMoney > 0 && $customerTotal > $shopPaidMoney) {
                $platformFee = round($customerTotal - $shopPaidMoney, 2);
                $this->invoiceService->createInvoice(
                    $order,
                    $order->getProvider(),
                    self::$foodPeople,
                    $platformFee,
                    $paidStatus,
                    new DateTime(),
                    $wallet,
                    $wallet
                );
            }

            // 3. Invoice de taxa de entrega: cobrada pela 99Food quando ela realiza a entrega
            if ($isPlatformDelivery && $deliveryFee > 0) {
                $this->invoiceService->createInvoice(
                    $order,
                    $order->getProvider(),
                    self::$foodPeople,
                    $deliveryFee,
                    $paidStatus,
                    new DateTime(),
                    $wallet,
                    $wallet
                );
            }
        } catch (\Throwable $e) {
            self::$logger->error('Food99 addPayments failed — order invoices not created', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function resolveModifierGroupName(string $appContentId, string $contentName): string
    {
        if ($contentName !== '') {
            return $contentName;
        }

        // 99Food retorna o grupo como MG_144; o número corresponde ao product_group.id interno
        if (preg_match('/^mg_(\d+)$/i', $appContentId, $m)) {
            $groupId = (int) $m[1];
            /** @var ProductGroup|null $productGroup */
            $productGroup = $this->entityManager->getRepository(ProductGroup::class)->find($groupId);
            if ($productGroup !== null) {
                return $productGroup->getProductGroup();
            }
        }

        return $appContentId;
    }

    private function addProducts(
        Order $order,
        array $items,
        ?Product $parentProduct = null,
        ?OrderProduct $orderParentProduct = null
    ) {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            try {
                $productType = $parentProduct ? 'component' : 'product';

                $product = $this->discoveryProduct($order, $item, $parentProduct, $productType);

                $productGroup = null;

                if ($parentProduct && !empty($item['app_content_id'])) {
                    $productGroup = $this->productGroupService->discoveryProductGroup(
                        $parentProduct,
                        $item['app_content_id'],
                        $this->resolveModifierGroupName($item['app_content_id'], $item['content_name'] ?? '')
                    );
                }

                $orderProduct = $this->orderProductService->addOrderProduct(
                    $order,
                    $product,
                    $item['amount'] ?? 1,
                    isset($item['sku_price']) ? ((float) $item['sku_price']) / 100 : 0,
                    $productGroup,
                    $parentProduct,
                    $orderParentProduct
                );
                $this->syncOrderProductComment($orderProduct, $this->extractItemRemark($item));

                if (!empty($item['sub_item_list']) && is_array($item['sub_item_list'])) {
                    $this->addProducts(
                        $order,
                        $item['sub_item_list'],
                        $product,
                        $orderProduct
                    );
                }
            } catch (\Throwable $e) {
                self::$logger->error('Food99 order item could not be processed and was skipped', [
                    'local_order_id' => $order->getId(),
                    'provider_id' => $order->getProvider()?->getId(),
                    'item' => $item,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function discoveryProduct(
        Order $order,
        array $item,
        ?Product $parentProduct,
        string $productType
    ): Product {
        $code = $this->resolveIncomingProductCode($item, $productType);

        $product = $this->findFood99EntityByExtraData('Product', 'code', $code, Product::class);

        if (!$product) {
            $unity = $this->entityManager
                ->getRepository(ProductUnity::class)
                ->findOneBy(['productUnit' => 'UN']);

            if (!$unity instanceof ProductUnity) {
                throw new \RuntimeException('Product unity UN not found for Food99 product creation.');
            }

            $product = new Product();
            $product->setProduct($item['name'] ?? 'Produto Food99');
            $product->setSku(null);
            $product->setPrice(isset($item['sku_price']) ? ((float) $item['sku_price']) / 100 : 0);
            $product->setProductUnit($unity);
            $product->setType($productType);
            $product->setProductCondition('new');
            $product->setCompany($order->getProvider());

            $this->entityManager->persist($product);
            $this->entityManager->flush();
        }

        if ($parentProduct && !empty($item['app_content_id'])) {
            $group = $this->productGroupService->discoveryProductGroup(
                $parentProduct,
                $item['app_content_id'],
                $this->resolveModifierGroupName($item['app_content_id'], $item['content_name'] ?? '')
            );

            $exists = $this->entityManager
                ->getRepository(ProductGroupProduct::class)
                ->findOneBy([
                    'product' => $parentProduct,
                    'productChild' => $product,
                    'productGroup' => $group
                ]);

            if (!$exists) {
                $pgp = new ProductGroupProduct();
                $pgp->setProduct($parentProduct);
                $pgp->setProductChild($product);
                $pgp->setProductGroup($group);
                $pgp->setProductType($productType);
                $pgp->setQuantity($item['amount'] ?? 1);
                $pgp->setPrice(isset($item['sku_price']) ? ((float) $item['sku_price']) / 100 : 0);

                $this->entityManager->persist($pgp);
                $this->entityManager->flush();
            }
        }

        $this->persistLocalFoodCodeByEntity('Product', (int) $product->getId(), (string) $code);

        return $product;
    }

    private function discoveryClient(array $address, ?People $provider = null): ?People
    {
        $resolvedName = $this->resolveFood99CustomerName($address, '');
        $remoteClientId = $this->resolveFood99RemoteClientId($address);

        if ($remoteClientId !== '') {
            $client = $this->extraDataService->getEntityByExtraData(
                self::APP_CONTEXT,
                'code',
                $remoteClientId,
                People::class
            );

            if ($client instanceof People) {
                return $provider instanceof People
                    ? $this->syncFood99ClientData($client, $provider, $address, $remoteClientId)
                    : $client;
            }
        }

        $phone = $this->resolveFood99ClientPhone($address);
        if (empty($phone) && $resolvedName === '') {
            return null;
        }

        $client = $this->peopleService->discoveryPeople(
            null,
            null,
            $phone,
            $resolvedName !== '' ? $resolvedName : 'Cliente Food99',
            'F'
        );

        if (!$client instanceof People) {
            return null;
        }

        return $provider instanceof People
            ? $this->syncFood99ClientData($client, $provider, $address, $remoteClientId)
            : $client;
    }

    private function addAddress(Order $order, array $address)
    {
        if (!$address) {
            return;
        }

        $rawPostal = $address['postal_code'] ?? null;
        $postalCode = $rawPostal !== null ? (int) preg_replace('/\D+/', '', (string) $rawPostal) : 0;

        if ($postalCode <= 0) {
            self::$logger->warning('Food99 address missing/invalid postal_code (skipping address)', [
                'postal_code' => $rawPostal,
                'address_keys' => array_keys($address),
            ]);
            return;
        }

        $addr = $this->addressService->discoveryAddress(
            $order->getClient(),
            $postalCode,
            $address['street_number'] ?? null,
            $address['street_name'] ?? null,
            $address['district'] ?? null,
            $address['city'] ?? null,
            $address['state'] ?? null,
            $address['country_code'] ?? null,
            $address['complement'] ?? null,
            $address['poi_lat'] ?? 0,
            $address['poi_lng'] ?? 0
        );

        $order->setAddressDestination($addr);
    }
    public static function getSubscribedEvents(): array
    {
        return [
            EntityChangedEvent::class => 'onEntityChanged',
        ];
    }

    private function extractPendingOrderAction(Order $order): array
    {
        $otherInformations = $order->getOtherInformations(true);
        if (!is_object($otherInformations) || !isset($otherInformations->order_action)) {
            return [];
        }

        $action = $otherInformations->order_action;
        if (is_object($action)) {
            $action = (array) $action;
        }

        if (!is_array($action)) {
            return [];
        }

        $payload = $action['payload'] ?? [];
        if (is_object($payload)) {
            $payload = (array) $payload;
        }

        return [
            'name' => strtolower($this->normalizeIncomingFood99Value($action['name'] ?? null)),
            'requested_at' => $this->normalizeIncomingFood99Value($action['requested_at'] ?? null),
            'remote_sync' => filter_var($action['remote_sync'] ?? false, FILTER_VALIDATE_BOOL),
            'payload' => is_array($payload) ? $payload : [],
        ];
    }

    private function hasPendingOrderActionChanged(Order $oldOrder, Order $newOrder): bool
    {
        $oldAction = $this->extractPendingOrderAction($oldOrder);
        $newAction = $this->extractPendingOrderAction($newOrder);

        return ($oldAction['name'] ?? '') !== ($newAction['name'] ?? '')
            || ($oldAction['requested_at'] ?? '') !== ($newAction['requested_at'] ?? '')
            || (bool) ($oldAction['remote_sync'] ?? false) !== (bool) ($newAction['remote_sync'] ?? false);
    }

    public function onEntityChanged(EntityChangedEvent $event)
    {
        $oldEntity = $event->getOldEntity();
        $entity = $event->getEntity();

        if (!$entity instanceof Order || !$oldEntity instanceof Order)
            return;

        $this->init();
        if ($entity->getApp() !== self::APP_CONTEXT)
            return;

        $actionChanged = $this->hasPendingOrderActionChanged($oldEntity, $entity);

        if ($actionChanged)
            $this->changeStatus($entity);
    }

    public function changeStatus(Order $order)
    {
        $action = $this->extractPendingOrderAction($order);
        if (($action['remote_sync'] ?? false) !== true) {
            return null;
        }

        $payload = is_array($action['payload'] ?? null) ? $action['payload'] : [];
        $reasonId = $this->normalizeCancelReasonId($payload['reason_id'] ?? null);
        $reason = $this->normalizeIncomingFood99Value($payload['reason'] ?? null);
        $deliveryCode = $this->normalizeIncomingFood99Value($payload['delivery_code'] ?? null);
        $locator = $this->normalizeIncomingFood99Value($payload['locator'] ?? null);

        match ($action['name'] ?? '') {
            'cancel' => $this->performCancelAction(
                $order,
                $reasonId,
                $reason !== '' ? $reason : null
            ),
            'ready' => $this->performReadyAction($order),
            'delivered' => $this->performDeliveredAction(
                $order,
                $deliveryCode !== '' ? $deliveryCode : null,
                $locator !== '' ? $locator : null
            ),
            default     => null,
        };

        return null;
    }
}
