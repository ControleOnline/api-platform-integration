<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\ProductUnity;
use ControleOnline\Event\EntityChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class Food99Service extends DefaultFoodService implements EventSubscriberInterface
{
    private const APP_CONTEXT = 'Food99';
    private const LEGACY_ORDER_CONTEXT = 'iFood';
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
        foreach (['auth_token', 'app_secret', 'appSecret', 'access_token', 'finance_access_token'] as $secretKey) {
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
            $success = ($payload['errno'] ?? 1) === 0;

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

    public function deliveredOrder(string $orderId, ?People $provider = null): ?array
    {
        return $this->call99EndpointWithResponse('/v1/order/order/delivered', [
            'order_id' => $orderId,
        ], $provider);
    }

    public function cancelByShop(string $orderId, ?People $provider = null): ?array
    {
        return $this->call99EndpointWithResponse('/v1/order/order/cancel', [
            'order_id' => $orderId,
            'reason_id' => 1080,
            'reason' => 'Cancelled by merchant system',
        ], $provider);
    }

    private function buildUnavailableOrderActionResponse(string $message): array
    {
        return [
            'errno' => 10001,
            'errmsg' => $message,
            'data' => [],
        ];
    }

    private function persistOrderActionResult(Order $order, string $action, ?array $response): array
    {
        $safeResponse = is_array($response)
            ? $response
            : $this->buildUnavailableOrderActionResponse('Nao foi possivel executar a acao no pedido da 99Food.');

        $success = ($safeResponse['errno'] ?? 1) === 0;

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
                    'cancel_code' => '1080',
                    'cancel_reason' => 'Cancelled by merchant system',
                ]);
                $this->applyLocalCanceledStatus($order);
            } elseif ($action === 'ready') {
                $this->persistOrderIntegrationState($order, [
                    'remote_order_state' => 'ready',
                ]);
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

        return $safeResponse;
    }

    private function resolveRemoteOrderId(Order $order): ?string
    {
        $state = $this->getStoredOrderIntegrationState($order);

        return $state['food99_id']
            ?: $state['food99_code'];
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

    public function performCancelAction(Order $order): array
    {
        $orderId = $this->resolveRemoteOrderId($order);
        if (!$orderId) {
            return $this->buildUnavailableOrderActionResponse('Pedido 99Food sem identificador remoto.');
        }

        return $this->persistOrderActionResult(
            $order,
            'cancel',
            $this->cancelByShop($orderId, $order->getProvider())
        );
    }

    public function performDeliveredAction(Order $order): array
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

        return $this->persistOrderActionResult(
            $order,
            'delivered',
            $this->deliveredOrder($orderId, $order->getProvider())
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
                'response' => $response->toArray(false),
            ]);
        } catch (\Throwable $e) {
            self::$logger->error('Food99 ACTION ERROR', [
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
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
                'response' => $result,
            ]);

            return $result;
        } catch (\Throwable $e) {
            self::$logger->error('Food99 ACTION ERROR', [
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
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
                'response' => $result,
            ], $logContext));

            return $result;
        } catch (\Throwable $e) {
            self::$logger->error('Food99 ACTION ERROR', array_merge([
                'method' => $method,
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
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
        ];

        $state = array_merge($state, $this->extractOrderDeliveryStateFields($payload));
        $state = array_merge($state, $this->resolveOrderDeliveryFlags($state));
        $state['allows_manual_delivery_completion'] = $state['is_store_delivery'];

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

    private function findExistingIntegratedOrder(string $orderId, string $orderCode): ?Order
    {
        if ($orderId !== '') {
            $order = $this->findFood99OrderByLegacyAwareExtraData('id', $orderId);
            if ($order instanceof Order) {
                return $order;
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

    private function waitForExistingIntegratedOrder(string $orderId, string $orderCode, int $attempts = 5, int $sleepMicroseconds = 250000): ?Order
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $existing = $this->findExistingIntegratedOrder($orderId, $orderCode);
            if ($existing instanceof Order) {
                return $existing;
            }

            usleep($sleepMicroseconds);
        }

        return null;
    }

    private function resolveOrderClient(array $address, string $orderId): People
    {
        $client = $this->discoveryClient($address);
        if ($client instanceof People) {
            return $client;
        }

        $nameParts = array_filter([
            $this->normalizeIncomingFood99Value($address['name'] ?? null),
            $this->normalizeIncomingFood99Value($address['first_name'] ?? null),
            $this->normalizeIncomingFood99Value($address['last_name'] ?? null),
        ]);
        $fallbackName = trim(implode(' ', array_unique($nameParts)));
        if ($fallbackName === '') {
            $fallbackName = 'Cliente Food99';
        }

        $clientCode = $this->normalizeIncomingFood99Value($address['uid'] ?? null);
        if ($clientCode === '') {
            $clientCode = 'food99-order-' . $orderId;
        }

        self::$logger->warning('Food99 order received without a resolved customer name; using fallback customer record', [
            'order_id' => $orderId,
            'client_code' => $clientCode,
            'address_keys' => array_keys($address),
        ]);

        $client = $this->peopleService->discoveryPeople(
            $clientCode,
            null,
            null,
            $fallbackName
        );

        $this->persistLocalFoodCodeByEntity('People', (int) $client->getId(), $clientCode);

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
            'delivery_type' => $this->getFood99OrderExtraDataValue($orderId, 'delivery_type'),
            'fulfillment_mode' => $this->getFood99OrderExtraDataValue($orderId, 'fulfillment_mode'),
            'expected_arrived_eta' => $this->getFood99OrderExtraDataValue($orderId, 'expected_arrived_eta'),
            'locator' => $this->getFood99OrderExtraDataValue($orderId, 'locator'),
            'handover_page_url' => $this->getFood99OrderExtraDataValue($orderId, 'handover_page_url'),
            'virtual_phone_number' => $this->getFood99OrderExtraDataValue($orderId, 'virtual_phone_number'),
            'handover_code' => $this->getFood99OrderExtraDataValue($orderId, 'handover_code'),
        ];

        $fallbackState = $this->extractOrderIntegrationStateFromOtherInformations($order);

        foreach ($state as $key => $value) {
            if ($value !== null && $value !== '') {
                continue;
            }

            $state[$key] = $fallbackState[$key] ?? $value;
        }

        $state = array_merge($state, $this->resolveOrderDeliveryFlags($state));
        $state['allows_manual_delivery_completion'] = $state['is_store_delivery'];

        return $state;
    }

    private function extractOrderDeliveryStateFields(array $json): array
    {
        return [
            'delivery_type' => $this->extractOrderDeliveryType($json),
            'fulfillment_mode' => $this->extractOrderFulfillmentMode($json),
            'expected_arrived_eta' => $this->extractOrderExpectedArrivedEta($json),
            'locator' => $this->extractOrderLocator($json),
            'handover_page_url' => $this->extractOrderHandoverPageUrl($json),
            'virtual_phone_number' => $this->extractOrderVirtualPhoneNumber($json),
            'handover_code' => $this->extractOrderHandoverCode($json),
        ];
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

        if ($deliveryType === '1') {
            $isStoreDelivery = true;
            $deliveryLabel = 'Entrega da loja';
        } elseif ($deliveryType === '2') {
            $isPlatformDelivery = true;
            $deliveryLabel = 'Entrega 99';
        } elseif ($locator !== '' || $handoverPageUrl !== '' || $virtualPhoneNumber !== '' || $handoverCode !== '') {
            $isPlatformDelivery = true;
            $deliveryLabel = 'Entrega 99';
        }

        return [
            'is_store_delivery' => $isStoreDelivery,
            'is_platform_delivery' => $isPlatformDelivery,
            'delivery_label' => $deliveryLabel,
        ];
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
        $remoteState = $this->resolveCanonicalRemoteOrderState($eventType, $deliveryStatus);
        $eventTimestamp = $this->extractOrderEventTimestamp($json);
        $isCanceled = $this->shouldApplyLocalCanceledStatus($remoteState, $eventType);
        $isClosed = !$isCanceled && $this->shouldApplyLocalClosedStatus($remoteState, $eventType, $deliveryStatus);

        $this->storeOrderRemoteSnapshot($order, $eventType !== '' ? $eventType : 'unknownEvent', $json);

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

        if ($isCanceled) {
            $integrationState['cancel_code'] = $this->extractOrderCancelCode($json);
            $integrationState['cancel_reason'] = $this->extractOrderCancelReason($json);
        }

        $this->persistOrderIntegrationState($order, array_merge(
            $integrationState,
            $this->extractOrderDeliveryStateFields($json)
        ));

        if ($isCanceled) {
            $this->applyLocalCanceledStatus($order);
        } elseif ($isClosed) {
            $this->applyLocalClosedStatus($order);
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

    private function resolveCanonicalRemoteOrderState(string $eventType, ?string $deliveryStatus = null): ?string
    {
        $normalizedEventType = strtolower(trim($eventType));

        if ($normalizedEventType === '') {
            return $this->resolveRemoteOrderStateFromDeliveryStatus($deliveryStatus);
        }

        if (str_contains($normalizedEventType, 'cancel')) {
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

    private function shouldApplyLocalCanceledStatus(?string $remoteState, string $eventType): bool
    {
        $normalizedState = strtolower(trim((string) $remoteState));
        if (in_array($normalizedState, ['cancelled', 'canceled'], true)) {
            return true;
        }

        return str_contains(strtolower(trim($eventType)), 'cancel');
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
        $appShopId = isset($json['app_shop_id'])
            ? (int) preg_replace('/\D+/', '', (string) $json['app_shop_id'])
            : 0;

        if ($appShopId <= 0) {
            self::$logger->warning('Food99 shopStatus webhook ignored because app_shop_id is missing', [
                'payload' => $json,
            ]);
            return;
        }

        $provider = $this->entityManager->getRepository(People::class)->find($appShopId);
        if (!$provider instanceof People) {
            self::$logger->warning('Food99 shopStatus webhook ignored because provider was not found', [
                'app_shop_id' => $appShopId,
            ]);
            return;
        }

        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $this->persistProviderStoreState($provider, $data);
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
        if (($response['errno'] ?? 1) === 0) {
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

        return $this->call99StoreEndpointWithResponse('POST', '/v3/image/image/uploadImage', $payload, $provider);
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
                ed.data_value AS food99_code,
                ed_published.data_value AS food99_published
            FROM product p
            LEFT JOIN product_category pc ON pc.id = (
                SELECT MIN(pc2.id)
                FROM product_category pc2
                INNER JOIN category c2 ON c2.id = pc2.category_id
                WHERE pc2.product_id = p.id
                  AND c2.context = 'products'
            )
            LEFT JOIN category c ON c.id = pc.category_id
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

    private function buildMenuProductView(array $row): array
    {
        $productId = (int) ($row['id'] ?? 0);
        $productName = trim((string) ($row['product_name'] ?? ''));
        $categoryId = isset($row['category_id']) && $row['category_id'] !== null ? (int) $row['category_id'] : null;
        $categoryName = $row['category_name'] ? trim((string) $row['category_name']) : null;
        $price = round((float) ($row['price'] ?? 0), 2);
        $appItemId = trim((string) ($row['food99_code'] ?? '')) ?: (string) $productId;
        $published = in_array((string) ($row['food99_published'] ?? ''), ['1', 'true'], true);

        $blockers = [];
        if ($productName === '') {
            $blockers[] = 'Produto sem nome';
        }
        if (!$categoryId) {
            $blockers[] = 'Produto sem categoria';
        }
        if ($price <= 0) {
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

            $remoteConnected = is_array($storeDetails) && (($storeDetails['errno'] ?? 1) === 0);
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
        if (($storeDetails['errno'] ?? 1) !== 0) {
            $sync['errors']['store'] = $storeDetails['errmsg'] ?? 'Nao foi possivel sincronizar os detalhes da loja.';
        }

        $deliveryAreas = $this->listDeliveryAreas($provider);
        $sync['delivery_areas'] = $deliveryAreas;
        if (($deliveryAreas['errno'] ?? 1) !== 0) {
            $sync['errors']['delivery_areas'] = $deliveryAreas['errmsg'] ?? 'Nao foi possivel sincronizar as areas de entrega.';
        }

        $menuDetails = $this->getStoreMenuDetails($provider);
        $sync['menu'] = $menuDetails;
        if (($menuDetails['errno'] ?? 1) !== 0) {
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
        $items = [];
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

            $categoriesMap[$categoryId]['app_item_ids'][] = (string) $product['suggested_app_item_id'];
            $items[] = [
                'app_item_id' => (string) $product['suggested_app_item_id'],
                'item_name' => $product['name'],
                'short_desc' => $product['description'],
                'price' => (int) round($product['price'] * 100),
                'status' => 1,
                'priority' => $index + 1,
                'is_sold_separately' => true,
            ];
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
            'items' => $items,
            'modifier_groups' => [],
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
        $modifierItemIds = $this->getModifierGroupItemIds(is_array($payload['modifier_groups'] ?? null) ? $payload['modifier_groups'] : []);
        $sellableItemIds = [];

        foreach (($payload['items'] ?? []) as $item) {
            if (!is_array($item) || empty($item['app_item_id'])) {
                continue;
            }

            $itemId = (string) $item['app_item_id'];

            if (in_array($itemId, $modifierItemIds, true)) {
                continue;
            }

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

        $response = $this->call99EndpointWithResponse('/v3/item/item/upload', $payload, $provider);
        $taskId = is_array($response['data'] ?? null) ? ($response['data']['taskID'] ?? null) : null;
        $response = is_array($response) ? $this->normalizeMenuTaskResponse($response, $taskId) : $response;

        if (($response['errno'] ?? 1) === 0) {
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

        if (($response['errno'] ?? 1) !== 0) {
            $this->persistProviderLastError($provider, $response['errno'] ?? null, $response['errmsg'] ?? null);
            return $response;
        }

        $taskState = $this->persistProviderMenuTaskState($provider, $response, $taskId);

        if ($taskState === 'completed') {
            $menuResponse = $this->getStoreMenuDetails($provider);
            if (($menuResponse['errno'] ?? 1) === 0) {
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

        if (($json['type'] ?? null) === 'shopStatus') {
            $this->syncStoreStatusWebhook($json);
            return null;
        }

        return match ($json['type'] ?? null) {
            'orderNew' => $this->addOrder($json),
            'orderCancel' => $this->handleOrderCancelEvent($json),
            'orderFinish' => $this->handleOrderFinishEvent($json),
            'deliveryStatus' => $this->handleDeliveryStatusEvent($json),
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
        if (!$lockAcquired) {
            $existing = $this->waitForExistingIntegratedOrder($orderId, $orderCode);
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
            $exists = $this->findExistingIntegratedOrder($orderId, $orderCode);
            if ($exists instanceof Order) {
                self::$logger->info('Food99 order already integrated, skipping duplicate creation', $this->buildLogContext(null, $json, [
                    'local_order_id' => $exists->getId(),
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

            $client = $this->resolveOrderClient($receiveAddress, $orderId);
            $status = $this->statusService->discoveryStatus('open', 'paid', 'order');
            $orderPrice = isset($price['order_price']) ? ((float) $price['order_price']) / 100 : 0.0;

            $order = $this->createOrder($client, $provider, $orderPrice, $status, $json);
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
                'error' => $e->getMessage(),
            ]);

            return $this->persistOrderConfirmResult($order, [
                'errno' => $errorCode,
                'errmsg' => $e->getMessage(),
                'data' => [],
            ]);
        }
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
                        $item['content_name'] ?: $item['app_content_id']
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
                $item['content_name'] ?: $item['app_content_id']
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

    private function discoveryClient(array $address): ?People
    {
        if (empty($address['name'])) {
            return null;
        }

        $client = $this->peopleService->discoveryPeople(
            $address['uid'] ?? null,
            null,
            null,
            $address['name']
        );

        $uid = (string) ($address['uid'] ?? '');
        if ($uid !== '') {
            $this->persistLocalFoodCodeByEntity('People', (int) $client->getId(), $uid);
        }

        return $client;
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

    public function onEntityChanged(EntityChangedEvent $event)
    {
        $oldEntity = $event->getOldEntity();
        $entity = $event->getEntity();

        if (!$entity instanceof Order || !$oldEntity instanceof Order)
            return;

        $this->init();
        if ($entity->getApp() !== self::APP_CONTEXT)
            return;

        if ($oldEntity->getStatus()->getId() != $entity->getStatus()->getId())
            $this->changeStatus($entity);
    }

    public function changeStatus(Order $order)
    {
        $orderId = $this->findLocalFoodIdByEntity('Order', (int) $order->getId())
            ?: $this->findLocalFoodCodeByEntity('Order', (int) $order->getId());

        if (!$orderId) {
            self::$logger->warning('Food99 changeStatus skipped because external order code was not found', [
                'local_order_id' => $order->getId(),
                'real_status' => $order->getStatus()->getRealStatus(),
            ]);
            return null;
        }

        $realStatus = $order->getStatus()->getRealStatus();

        self::$logger->info('Food99 status sync requested', [
            'local_order_id' => $order->getId(),
            'order_id' => $orderId,
            'real_status' => $realStatus,
        ]);


        match ($realStatus) {
            'cancelled' => $this->cancelByShop($orderId, $order->getProvider()),
            'ready'     => $this->readyOrder($orderId, $order->getProvider()),
            'delivered' => $this->deliveredOrder($orderId, $order->getProvider()),
            default     => null,
        };

        return null;
    }
}
