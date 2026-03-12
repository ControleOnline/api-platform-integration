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
    private static array $authTokenCache = [];

    private function init()
    {
        self::$app = 'Food99';
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
            $providerCode = $this->discoveryFoodCodeByEntity($provider);
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


    public function readyOrder(string $orderId, ?People $provider = null): void
    {
        $this->call99Endpoint('/v1/order/order/ready', [
            'order_id' => $orderId,
        ], $provider);
    }

    public function deliveredOrder(string $orderId, ?People $provider = null): void
    {
        $this->call99Endpoint('/v1/order/order/delivered', [
            'order_id' => $orderId,
        ], $provider);
    }

    public function cancelByShop(string $orderId, ?People $provider = null): void
    {
        $this->call99Endpoint('/v1/order/order/cancel', [
            'order_id' => $orderId,
            'reason_id' => 1080,
            'reason' => 'Cancelled by merchant system',
        ], $provider);
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

        $code = $this->discoveryFoodCodeByEntity($provider);
        if ($code === null || $code === '') {
            $sql = <<<SQL
                SELECT ed.data_value
                FROM extra_data ed
                INNER JOIN extra_fields ef ON ef.id = ed.extra_fields_id
                WHERE ef.context = :context
                  AND ef.field_name = :fieldName
                  AND ed.entity_name = :entityName
                  AND ed.entity_id = :entityId
                ORDER BY ed.id DESC
                LIMIT 1
            SQL;

            $code = $this->entityManager->getConnection()->fetchOne($sql, [
                'context' => self::$app,
                'fieldName' => 'code',
                'entityName' => 'People',
                'entityId' => $provider->getId(),
            ]);
        }

        if ($code === null || $code === '') {
            return null;
        }

        return (string) $code;
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

        return $this->call99StoreEndpointWithResponse('GET', '/v1/shop/shop/detail', [], $provider);
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

        return $this->call99StoreEndpointWithResponse('POST', '/v1/shop/shop/setStatus', $payload, $provider);
    }

    public function setStoreCancellationRefund(People $provider, array $payload): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('POST', '/v1/shop/apply/set', $payload, $provider);
    }

    public function getStoreMenuDetails(People $provider): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('GET', '/v3/item/item/list', [], $provider);
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

        return $this->call99StoreEndpointWithResponse('GET', '/v1/shop/deliveryArea/list', [], $provider);
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
            'food99Context' => self::$app,
            'codeFieldName' => 'code',
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
                ed.data_value AS food99_code
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
        $authToken = $this->resolveAccessToken($provider);
        $authAvailable = !empty($authToken);

        $storeDetails = $authAvailable ? $this->getStoreDetails($provider) : null;
        $deliveryAreas = $authAvailable ? $this->listDeliveryAreas($provider) : null;
        $menuDetails = $authAvailable ? $this->getStoreMenuDetails($provider) : null;

        $remoteConnected = is_array($storeDetails) && (($storeDetails['errno'] ?? 1) === 0);
        $connected = !empty($integratedStoreCode) || $remoteConnected;
        $remoteStore = is_array($storeDetails['data'] ?? null) ? $storeDetails['data'] : null;
        $remoteItemIds = $this->resolvePublishedRemoteItemIds($menuDetails);
        $mappedProducts = $this->mapProductsWithRemoteCatalog($products['products'] ?? [], $remoteItemIds);
        $bizStatus = isset($remoteStore['biz_status']) ? (int) $remoteStore['biz_status'] : null;
        $subBizStatus = isset($remoteStore['sub_biz_status']) ? (int) $remoteStore['sub_biz_status'] : null;

        return [
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
                'remote_connected' => $remoteConnected,
                'food99_code' => $integratedStoreCode,
                'app_shop_id' => (string) $provider->getId(),
                'auth_available' => $authAvailable,
                'online' => $remoteConnected && $bizStatus === 1,
                'biz_status' => $bizStatus,
                'biz_status_label' => $this->resolveStatusLabel($bizStatus),
                'sub_biz_status' => $subBizStatus,
                'sub_biz_status_label' => $this->resolveSubStatusLabel($subBizStatus),
            ],
            'store' => $storeDetails,
            'delivery_areas' => $deliveryAreas,
            'menu' => array_merge($menuDetails ?? [], [
                'remote_item_ids' => $remoteItemIds,
            ]),
            'products' => [
                ...$products,
                'products' => $mappedProducts,
                'published_product_count' => count(array_filter(
                    $mappedProducts,
                    static fn(array $product) => !empty($product['published_remotely'])
                )),
            ],
        ];
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
                'app_category_ids' => array_keys($categoriesMap),
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
            $code = $this->discoveryFoodCodeByEntity($product);
            if (!$code) {
                $code = (string) $product->getId();
                $this->discoveryFoodCode($product, $code);
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

        return $this->call99EndpointWithResponse('/v3/item/item/upload', $payload, $provider);
    }

    public function getMenuUploadTaskInfo(People $provider, int|string $taskId): ?array
    {
        $this->init();

        return $this->call99EndpointWithResponse('/v1/item/item/getMenuTaskInfo', [
            'task_id' => $taskId,
        ], $provider);
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

        if (($json['type'] ?? null) !== 'orderNew') {
            self::$logger->info('Food99 event ignored because it is not implemented in current flow', $this->buildLogContext($integration, $json));
            return null;
        }

        return $this->addOrder($json);
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

        $orderId = (string)$data['order_id'];
        $orderIndex = (string)$data['order_info']['order_index'];

        $exists = $this->extraDataService->getEntityByExtraData(self::$app, 'code', $orderIndex, Order::class);
        if ($exists) {
            self::$logger->info('Food99 order already integrated, skipping duplicate creation', $this->buildLogContext(null, $json));
            return $exists;
        }

        $shopId = $shop['shop_id'] ?? null;

        $provider = null;
        if ($shopId) {
            $provider = $this->extraDataService->getEntityByExtraData(self::$app, 'code', $shopId, People::class);
        }

        if (!$provider) {
            $provider = $this->peopleService->discoveryPeople(
                null,
                null,
                null,
                $shop['shop_name'] ?? 'Loja Food99',
                'J'
            );
            $this->extraDataService->discoveryExtraData($provider, self::$app, 'code', $shopId);
        }

        $client = $this->discoveryClient($receiveAddress);
        $status = $this->statusService->discoveryStatus('open', 'paid', 'order');

        $orderPrice = $price['order_price'] ? $price['order_price'] / 100 : 0;

        $order = $this->createOrder($client, $provider, $orderPrice, $status, $json);

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

        $this->confirmOrder($orderId, $provider);

        $this->printOrder($order);
        $this->discoveryFoodCode($order, $orderId, 'id');
        return $this->discoveryFoodCode($order, $orderIndex);
    }

    private function confirmOrder(string $orderId, ?People $provider = null): void
    {
        $url = $this->getFood99BaseUrl() . '/v1/order/order/confirm';
        $accessToken = $this->resolveAccessToken($provider);

        if (!$accessToken) {
            self::$logger->warning('Food99 confirm skipped because access token is unavailable', [
                'order_id' => $orderId,
                'provider_id' => $provider?->getId(),
                'api_base_url' => $this->getFood99BaseUrl(),
            ]);
            return;
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
        } catch (\Throwable $e) {
            self::$logger->error('Food99 ORDER CONFIRM ERROR', [
                'order_id' => $orderId,
                'payload' => $this->sanitizePayloadForLog($payload),
                'error' => $e->getMessage(),
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
                $item['sku_price'] ? $item['sku_price'] / 100 : 0,
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
        }
    }

    private function discoveryProduct(
        Order $order,
        array $item,
        ?Product $parentProduct,
        string $productType
    ): Product {
        $code = $item['app_item_id'];

        $product = $this->extraDataService->getEntityByExtraData(
            self::$app,
            'code',
            $code,
            Product::class
        );

        if (!$product) {
            $unity = $this->entityManager
                ->getRepository(ProductUnity::class)
                ->findOneBy(['productUnit' => 'UN']);

            $product = new Product();
            $product->setProduct($item['name'] ?? 'Produto Food99');
            $product->setSku(null);
            $product->setPrice($item['sku_price'] ? $item['sku_price'] / 100 : 0);
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
                $pgp->setPrice($item['sku_price'] ? $item['sku_price'] / 100 : 0);

                $this->entityManager->persist($pgp);
                $this->entityManager->flush();
            }
        }

        return $this->discoveryFoodCode($product, $code);
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

        return $this->discoveryFoodCode(
            $client,
            (string) $address['uid']
        );
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
        if ($entity->getApp() !== self::$app)
            return;

        if ($oldEntity->getStatus()->getId() != $entity->getStatus()->getId())
            $this->changeStatus($entity);
    }

    public function changeStatus(Order $order)
    {
        $orderId = $this->discoveryFoodCodeByEntity($order);

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
