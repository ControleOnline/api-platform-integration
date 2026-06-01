<?php

namespace ControleOnline\Service\Client;

use ControleOnline\Entity\People;
use ControleOnline\Service\ConfigService;
use ControleOnline\Service\LoggerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class Food99Client
{
    private const API_BASE_URL = 'https://openapi.99food.com';
    private const BORDER_BASE_URL = 'https://b.99app.com';
    private const PORTAL_BASE_URL = 'https://openplatform-portal-food.99app.com';

    private static array $authTokenCache = [];

    private ?LoggerInterface $logger = null;

    public function __construct(
        private HttpClientInterface $httpClient,
        private ?LoggerService $loggerService = null,
        private ?ConfigService $configService = null,
    ) {}

    public function requestBorderWithResponse(string $method, string $uri, array $payload = [], array $logContext = []): ?array
    {
        return $this->requestWithResponse(self::BORDER_BASE_URL, $method, $uri, $payload, $logContext, false);
    }

    public function requestBorderMultipartWithResponse(string $method, string $uri, array $payload = [], array $logContext = []): ?array
    {
        return $this->requestWithResponse(self::BORDER_BASE_URL, $method, $uri, $payload, $logContext, true);
    }

    public function requestPortalWithResponse(string $method, string $uri, array $payload = [], array $logContext = []): ?array
    {
        return $this->requestWithResponse(self::PORTAL_BASE_URL, $method, $uri, $payload, $logContext, false);
    }

    public function callAppEndpointWithResponse(string $method, string $uri, array $payload = [], ?People $provider = null): ?array
    {
        $appId = $this->resolveAppId($provider);
        $appSecret = $this->resolveAppSecret($provider);

        if (!$appId || !$appSecret) {
            return null;
        }

        $payload['app_id'] = $payload['app_id'] ?? $appId;
        $payload['app_secret'] = $payload['app_secret'] ?? $appSecret;

        return $this->requestPortalWithResponse($method, $uri, $payload);
    }

    private function preparePortalPayload(array $payload): array
    {
        if (!array_key_exists('app_domain', $payload) && array_key_exists('appDomain', $payload)) {
            $payload['app_domain'] = $payload['appDomain'];
        }

        unset($payload['appDomain']);

        return $payload;
    }

    public function getAuthorizationPage(array $payload, ?People $provider = null): ?array
    {
        return $this->callAppEndpointWithResponse(
            'POST',
            '/shop_center/v1/authorize/get_url',
            $this->preparePortalPayload($payload),
            $provider
        );
    }

    public function bindStore(array $payload, ?People $provider = null): ?array
    {
        return $this->callAppEndpointWithResponse(
            'POST',
            '/shop_center/v1/authorize/bind',
            $this->preparePortalPayload($payload),
            $provider
        );
    }

    public function listAuthorizedStores(array $payload = [], ?People $provider = null): ?array
    {
        return $this->callAppEndpointWithResponse(
            'GET',
            '/shop_center/v1/authorize/list',
            $this->preparePortalPayload($payload),
            $provider
        );
    }

    public function listBindStores(array $payload = [], ?People $provider = null): ?array
    {
        return $this->callAppEndpointWithResponse(
            'GET',
            '/shop_center/v1/shop/list',
            $this->preparePortalPayload($payload),
            $provider
        );
    }

    public function unbindStore(array $payload = [], ?People $provider = null): ?array
    {
        return $this->callAppEndpointWithResponse(
            'POST',
            '/shop_center/v1/authorize/unbind',
            $this->preparePortalPayload($payload),
            $provider
        );
    }

    public function callStoreEndpointWithResponse(string $method, string $uri, array $payload = [], ?People $provider = null): ?array
    {
        $accessToken = $this->resolveAccessToken($provider);

        if (!$accessToken) {
            $this->logger()?->warning('Food99 action skipped because access token is unavailable', [
                'method' => strtoupper($method),
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'provider_id' => $provider?->getId(),
                'api_base_url' => self::BORDER_BASE_URL,
            ]);

            return null;
        }

        $payload['auth_token'] = $accessToken;

        return $this->requestBorderWithResponse($method, $uri, $payload, [
            'provider_id' => $provider?->getId(),
        ]);
    }

    public function callStoreMultipartEndpointWithResponse(string $method, string $uri, array $payload = [], ?People $provider = null): ?array
    {
        $accessToken = $this->resolveAccessToken($provider);

        if (!$accessToken) {
            $this->logger()?->warning('Food99 action skipped because access token is unavailable', [
                'method' => strtoupper($method),
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'provider_id' => $provider?->getId(),
                'api_base_url' => self::BORDER_BASE_URL,
            ]);

            return null;
        }

        $payload['auth_token'] = $accessToken;

        return $this->requestBorderMultipartWithResponse($method, $uri, $payload, [
            'provider_id' => $provider?->getId(),
        ]);
    }

    public function setStoreOrderConfirmationMethod(People $provider, array $payload): ?array
    {
        return $this->callStoreEndpointWithResponse('POST', '/v1/shop/shop/setconfirmmethod', $payload, $provider);
    }

    public function getStoreOrderConfirmationMethod(People $provider): ?array
    {
        $postResponse = $this->callStoreEndpointWithResponse('POST', '/v1/shop/shop/getconfirmmethod', [], $provider);
        if ($this->isSuccessfulErrno($postResponse['errno'] ?? null)) {
            return $postResponse;
        }

        $getResponse = $this->callStoreEndpointWithResponse('GET', '/v1/shop/shop/getconfirmmethod', [], $provider);
        if ($this->isSuccessfulErrno($getResponse['errno'] ?? null)) {
            return $getResponse;
        }

        return $postResponse ?: $getResponse;
    }

    public function getStoreDetails(People $provider): ?array
    {
        return $this->callStoreEndpointWithResponse('GET', '/v1/shop/shop/detail', [], $provider);
    }

    public function updateStoreInformation(People $provider, array $payload): ?array
    {
        return $this->callStoreEndpointWithResponse('POST', '/v1/shop/shop/update', $payload, $provider);
    }

    public function getStoreCategories(People $provider): ?array
    {
        return $this->callStoreEndpointWithResponse('POST', '/v1/shop/shop/validCategories', [], $provider);
    }

    public function setStoreStatus(People $provider, int $bizStatus, ?int $autoSwitch = null): ?array
    {
        $payload = [
            'biz_status' => $bizStatus,
        ];

        if ($autoSwitch !== null) {
            $payload['auto_switch'] = $autoSwitch;
        }

        return $this->callStoreEndpointWithResponse('POST', '/v1/shop/shop/setStatus', $payload, $provider);
    }

    public function setStoreCancellationRefund(People $provider, array $payload): ?array
    {
        return $this->callStoreEndpointWithResponse('POST', '/v1/shop/apply/set', $payload, $provider);
    }

    public function getStoreMenuDetails(People $provider): ?array
    {
        return $this->callStoreEndpointWithResponse('GET', '/v3/item/item/list', [], $provider);
    }

    public function updateMenuItem(People $provider, array $payload): ?array
    {
        return $this->callStoreEndpointWithResponse('POST', '/v3/item/item/updateItem', $payload, $provider);
    }

    public function updateMenuItemStatus(People $provider, array $payload): ?array
    {
        return $this->callStoreEndpointWithResponse('POST', '/v3/item/item/updateItemStatus', $payload, $provider);
    }

    public function updateModifierGroup(People $provider, array $payload): ?array
    {
        return $this->callStoreEndpointWithResponse('POST', '/v3/item/item/updateModifierGroup', $payload, $provider);
    }

    public function uploadImage(People $provider, array $payload): ?array
    {
        return $this->callStoreMultipartEndpointWithResponse('POST', '/v3/image/image/uploadImage', $payload, $provider);
    }

    public function getImageUploadInfoPageList(People $provider, array $payload = []): ?array
    {
        return $this->callStoreEndpointWithResponse('GET', '/v3/image/image/getImageUploadInfoPageList', $payload, $provider);
    }

    public function getOrderDetails(People $provider, string $orderId): ?array
    {
        return $this->callOpenApiEndpointWithResponse('GET', '/v1/order/order/detail', [
            'order_id' => $orderId,
        ], $provider);
    }

    public function confirmRemoteOrder(string $orderId, ?People $provider = null): ?array
    {
        return $this->callStoreEndpointWithResponse('POST', '/v1/order/order/confirm', [
            'order_id' => $orderId,
        ], $provider);
    }

    public function handleCancellationRequest(People $provider, array $payload): ?array
    {
        return $this->callStoreEndpointWithResponse('POST', '/v1/order/apply/cancel', $payload, $provider);
    }

    public function handleRefundRequest(People $provider, array $payload): ?array
    {
        return $this->callStoreEndpointWithResponse('POST', '/v1/order/apply/refund', $payload, $provider);
    }

    public function verifyOrder(People $provider, array $payload): ?array
    {
        return $this->callStoreEndpointWithResponse('POST', '/v1/order/order/verify', $payload, $provider);
    }

    public function confirmCashPayment(People $provider, array $payload): ?array
    {
        return $this->callStoreEndpointWithResponse('POST', '/v1/order/order/payConfirm', $payload, $provider);
    }

    public function listDeliveryAreas(People $provider): ?array
    {
        return $this->callStoreEndpointWithResponse('GET', '/v1/shop/deliveryArea/list', [], $provider);
    }

    public function addDeliveryArea(People $provider, array $payload): ?array
    {
        return $this->callStoreEndpointWithResponse('POST', '/v1/shop/deliveryArea/add', $payload, $provider);
    }

    public function updateDeliveryArea(People $provider, array $payload): ?array
    {
        return $this->callStoreEndpointWithResponse('POST', '/v1/shop/deliveryArea/update', $payload, $provider);
    }

    public function deleteDeliveryArea(People $provider, array $payload): ?array
    {
        return $this->callStoreEndpointWithResponse('POST', '/v1/shop/deliveryArea/delete', $payload, $provider);
    }

    public function getFinancialApiAuthtoken(array $payload): ?array
    {
        return $this->requestBorderWithResponse('POST', '/v3/auth/authtoken/signIn', $payload);
    }

    public function getBillData(array $payload): ?array
    {
        return $this->requestBorderWithResponse('POST', '/v3/finance/finance/getShopBillDetail', $payload);
    }

    public function getSettlementsData(array $payload): ?array
    {
        return $this->requestBorderWithResponse('POST', '/v3/finance/finance/getShopBillWeek', $payload);
    }

    public function callOrderEndpointWithResponse(string $uri, array $payload, ?People $provider = null): ?array
    {
        return $this->callOpenApiEndpointWithResponse('POST', $uri, $payload, $provider);
    }

    public function callOpenApiEndpointWithResponse(string $method, string $uri, array $payload = [], ?People $provider = null): ?array
    {
        $accessToken = $this->resolveAccessToken($provider);

        if (!$accessToken) {
            $this->logger()?->warning('Food99 action skipped because access token is unavailable', [
                'method' => strtoupper($method),
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'provider_id' => $provider?->getId(),
                'api_base_url' => self::API_BASE_URL,
            ]);

            return null;
        }

        $payload['auth_token'] = $accessToken;

        return $this->requestOpenApiWithResponse($method, $uri, $payload, [
            'provider_id' => $provider?->getId(),
        ]);
    }

    public function downloadContent(string $url): ?string
    {
        $normalizedUrl = trim($url);
        if ($normalizedUrl === '' || !preg_match('/^https?:\\/\\//i', $normalizedUrl)) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $normalizedUrl);
            if ($response->getStatusCode() >= 400) {
                return null;
            }

            $content = $response->getContent(false);

            return $content !== '' ? $content : null;
        } catch (\Throwable $e) {
            $this->logger()?->warning('Food99 image download failed', [
                'url' => $normalizedUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function callOpenDeliveryEndpointWithResponse(
        string $method,
        string $uri,
        array $payload = [],
        ?People $provider = null
    ): ?array {
        $accessToken = $this->resolveAccessToken($provider);

        if (!$accessToken) {
            $this->logger()?->warning('Food99 open delivery action skipped because access token is unavailable', [
                'method' => strtoupper($method),
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'provider_id' => $provider?->getId(),
                'api_base_url' => self::API_BASE_URL,
            ]);

            return null;
        }

        $headers = [
            'Authorization' => 'Bearer ' . $accessToken,
        ];

        $method = strtoupper($method);
        if ($method === 'GET' && $payload !== []) {
            $uri .= (str_contains($uri, '?') ? '&' : '?') . $this->buildQueryString($payload);
            $payload = [];
        }

        return $this->requestOpenApiWithResponse($method, $uri, $payload, [
            'provider_id' => $provider?->getId(),
        ], $headers);
    }

    public function pollOpenDeliveryEvents(?People $provider, array $eventTypes, string $fromTime): ?array
    {
        return $this->callOpenDeliveryEndpointWithResponse('GET', '/v4/opendelivery/v1/events:polling', [
            'eventType' => array_values(array_filter(array_map(
                static fn (mixed $eventType): string => strtoupper(trim((string) $eventType)),
                $eventTypes
            ))),
            'fromTime' => $fromTime,
        ], $provider);
    }

    public function acknowledgeOpenDeliveryEvents(?People $provider, array $events): ?array
    {
        $acknowledgements = [];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $eventId = trim((string) ($event['id'] ?? $event['eventId'] ?? $event['event_id'] ?? ''));
            $orderId = trim((string) ($event['orderId'] ?? $event['order_id'] ?? ''));
            $eventType = strtoupper(trim((string) ($event['eventType'] ?? $event['event_type'] ?? '')));

            if ($eventId === '' || $orderId === '' || $eventType === '') {
                continue;
            }

            $acknowledgements[] = [
                'id' => $eventId,
                'orderId' => $orderId,
                'eventType' => $eventType,
            ];
        }

        if ($acknowledgements === []) {
            return [
                'errno' => 0,
                'errmsg' => '',
                'data' => [],
            ];
        }

        return $this->callOpenDeliveryEndpointWithResponse(
            'POST',
            '/v4/opendelivery/v1/events/acknowledgment',
            $acknowledgements,
            $provider
        );
    }

    public function getOpenDeliveryOrderDetails(?People $provider, string $orderId): ?array
    {
        $normalizedOrderId = trim($orderId);
        if ($normalizedOrderId === '') {
            return null;
        }

        return $this->callOpenDeliveryEndpointWithResponse(
            'GET',
            '/v4/opendelivery/v1/orders/' . rawurlencode($normalizedOrderId),
            [],
            $provider
        );
    }

    public function getAccessToken(?People $provider = null): ?string
    {
        $appId = $this->resolveAppId($provider);
        $appSecret = $this->resolveAppSecret($provider);
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

    public function isAuthAvailable(?People $provider = null): bool
    {
        return $this->getAccessToken($provider) !== null;
    }

    public static function resetAccessTokenCache(): void
    {
        self::$authTokenCache = [];
    }

    public function resolveIntegrationAccessToken(People $provider): ?string
    {
        $appId = $this->resolveAppId($provider);
        $appSecret = $this->resolveAppSecret($provider);
        $appShopId = $this->resolveAppShopId($provider);

        if (!$appId || !$appSecret || !$appShopId) {
            return null;
        }

        $this->refreshAuthToken($appId, $appSecret, $appShopId);

        $tokenData = $this->requestAuthToken($appId, $appSecret, $appShopId, false);
        if (!$tokenData || empty($tokenData['auth_token'])) {
            $tokenData = $this->requestAuthToken($appId, $appSecret, $appShopId, true);
        }

        return !empty($tokenData['auth_token']) ? (string) $tokenData['auth_token'] : null;
    }

    private function requestOpenApiWithResponse(
        string $method,
        string $uri,
        array $payload,
        array $logContext = [],
        array $headers = []
    ): ?array
    {
        return $this->requestWithResponse(self::API_BASE_URL, $method, $uri, $payload, $logContext, false, $headers);
    }

    private function requestWithResponse(
        string $baseUrl,
        string $method,
        string $uri,
        array $payload,
        array $logContext = [],
        bool $multipart = false,
        array $headers = []
    ): ?array {
        $url = $baseUrl . $uri;
        $method = strtoupper($method);
        $startedAt = microtime(true);

        if ($multipart && $method !== 'POST') {
            $this->logger()?->warning('Food99 multipart request only supports POST', array_merge([
                'method' => $method,
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'api_base_url' => $baseUrl,
            ], $logContext));

            return null;
        }

        $requestOptions = [
            'headers' => array_merge([
                'Content-Type' => 'application/json',
            ], $headers),
        ];

        if ($multipart) {
            $formData = new FormDataPart($payload);
            $requestOptions['headers'] = $formData->getPreparedHeaders()->toArray();
            $requestOptions['body'] = $formData->bodyToIterable();
        } elseif ($method === 'GET') {
            $requestOptions['query'] = $payload;
        } else {
            $requestOptions['json'] = $payload;
        }

        try {
            $this->logger()?->info('Food99 ACTION REQUEST', array_merge([
                'method' => $method,
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'api_base_url' => $baseUrl,
                'content_type' => $multipart ? 'multipart/form-data' : 'application/json',
            ], $logContext));

            $response = $this->httpClient->request($method, $url, $requestOptions);
            $statusCode = $response->getStatusCode();
            $content = trim((string) $response->getContent(false));
            $result = [];

            if ($content !== '') {
                $decoded = json_decode($content, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $result = $decoded;
                } else {
                    throw new \RuntimeException('Food99 endpoint returned invalid JSON.');
                }
            } elseif (in_array($statusCode, [202, 204], true)) {
                $result = [
                    'errno' => 0,
                    'errmsg' => '',
                    'data' => [],
                ];
            }

            $this->logger()?->info('Food99 ACTION RESPONSE', array_merge([
                'method' => $method,
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'status_code' => $statusCode,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'response' => $result,
                'api_base_url' => $baseUrl,
            ], $logContext));

            return $result;
        } catch (\Throwable $e) {
            $this->logger()?->error('Food99 ACTION ERROR', array_merge([
                'method' => $method,
                'uri' => $uri,
                'payload' => $this->sanitizePayloadForLog($payload),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $e->getMessage(),
                'api_base_url' => $baseUrl,
            ], $logContext));

            return null;
        }
    }

    private function requestAuthToken(string $appId, string $appSecret, string $appShopId, bool $allowRefreshFallback = true): ?array
    {
        try {
            $response = $this->requestWithResponse(self::API_BASE_URL, 'GET', '/v1/auth/authtoken/get', [
                'app_id' => $appId,
                'app_secret' => $appSecret,
                'app_shop_id' => $appShopId,
            ]);

            $payload = is_array($response) ? $response : [];
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
                    $this->logger()?->info('Food99 auth token request requires refresh fallback', [
                        'app_shop_id' => $appShopId,
                        'errno' => $errno,
                        'errmsg' => $errmsg,
                    ]);

                    $refreshSuccess = $this->refreshAuthToken($appId, $appSecret, $appShopId);
                    if ($refreshSuccess) {
                        return $this->requestAuthToken($appId, $appSecret, $appShopId, false);
                    }
                }

                $this->logger()?->error('Food99 auth token request failed', [
                    'app_shop_id' => $appShopId,
                    'errno' => $errno,
                    'errmsg' => $errmsg,
                ]);

                return null;
            }

            self::$authTokenCache[$appShopId] = [
                'auth_token' => (string) $authToken,
                'token_expiration_time' => is_numeric($tokenExpirationTime) ? (int) $tokenExpirationTime : null,
            ];

            $this->logger()?->info('Food99 auth token fetched', [
                'app_shop_id' => $appShopId,
                'token_expiration_time' => self::$authTokenCache[$appShopId]['token_expiration_time'],
            ]);

            return self::$authTokenCache[$appShopId];
        } catch (\Throwable $e) {
            $this->logger()?->error('Food99 auth token request error', [
                'app_shop_id' => $appShopId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function refreshAuthToken(string $appId, string $appSecret, string $appShopId): bool
    {
        try {
            $response = $this->requestWithResponse(self::API_BASE_URL, 'GET', '/v1/auth/authtoken/refresh', [
                'app_id' => $appId,
                'app_secret' => $appSecret,
                'app_shop_id' => $appShopId,
            ]);

            $payload = is_array($response) ? $response : [];
            $success = $this->isSuccessfulErrno($payload['errno'] ?? null);

            $this->logger()?->info('Food99 auth token refresh response', [
                'app_shop_id' => $appShopId,
                'errno' => $payload['errno'] ?? null,
                'errmsg' => $payload['errmsg'] ?? null,
                'success' => $success,
            ]);

            return $success;
        } catch (\Throwable $e) {
            $this->logger()?->error('Food99 auth token refresh error', [
                'app_shop_id' => $appShopId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function resolveAccessToken(?People $provider = null): ?string
    {
        try {
            return $this->getAccessToken($provider);
        } catch (\Throwable $e) {
            $this->logger()?->error('Food99 access token resolution error', [
                'provider_id' => $provider?->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveAppId(?People $provider = null): ?string
    {
        return $this->resolveConfiguredValue(
            $provider,
            ['OAUTH_99FOOD_CLIENT_ID', 'OAUTH_99FOOD_APP_ID'],
            ['OAUTH_99FOOD_CLIENT_ID', 'OAUTH_99FOOD_APP_ID'],
            'Food99 app_id'
        );
    }

    private function resolveAppSecret(?People $provider = null): ?string
    {
        return $this->resolveConfiguredValue(
            $provider,
            ['OAUTH_99FOOD_CLIENT_SECRET', 'OAUTH_99FOOD_APP_SECRET'],
            ['OAUTH_99FOOD_CLIENT_SECRET', 'OAUTH_99FOOD_APP_SECRET'],
            'Food99 app_secret'
        );
    }

    private function resolveAppShopId(?People $provider = null): ?string
    {
        if ($provider?->getId()) {
            return (string) $provider->getId();
        }

        $this->logger()?->warning('Food99 app_shop_id could not be resolved', [
            'provider_id' => $provider?->getId(),
            'expected_provider_value' => 'People.id',
        ]);

        return null;
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

    private function sanitizePayloadForLog(array $payload): array
    {
        foreach (
            [
                'auth_token',
                'app_secret',
                'appSecret',
                'access_token',
                'finance_access_token',
                'deliveryCode',
                'delivery_code',
            ] as $secretKey
        ) {
            if (isset($payload[$secretKey])) {
                $payload[$secretKey] = '***';
            }
        }

        return $payload;
    }

    private function buildQueryString(array $payload): string
    {
        $pairs = [];

        $append = function (string $key, mixed $value) use (&$pairs, &$append): void {
            if (is_array($value)) {
                foreach ($value as $nestedKey => $nestedValue) {
                    $nextKey = is_int($nestedKey)
                        ? $key
                        : sprintf('%s[%s]', $key, (string) $nestedKey);
                    $append($nextKey, $nestedValue);
                }

                return;
            }

            if ($value === null) {
                return;
            }

            if ($value instanceof \DateTimeInterface) {
                $value = $value->format(\DateTimeInterface::ATOM);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            $pairs[] = rawurlencode($key) . '=' . rawurlencode((string) $value);
        };

        foreach ($payload as $key => $value) {
            $append((string) $key, $value);
        }

        return implode('&', $pairs);
    }

    private function resolveEnvironmentValue(string $name): string
    {
        return trim((string) (
            $_ENV[$name]
            ?? $_SERVER[$name]
            ?? getenv($name)
            ?: ''
        ));
    }

    private function resolveConfiguredValue(
        ?People $provider,
        array $configKeys,
        array $environmentKeys,
        string $label
    ): ?string {
        if ($this->configService instanceof ConfigService) {
            foreach ($configKeys as $configKey) {
                $configuredValue = trim((string) ($this->configService->getConfig($provider, $configKey) ?? ''));
                if ($configuredValue !== '') {
                    return $configuredValue;
                }
            }
        }

        foreach ($environmentKeys as $environmentKey) {
            $environmentValue = $this->resolveEnvironmentValue($environmentKey);
            if ($environmentValue !== '') {
                return $environmentValue;
            }
        }

        $this->logger()?->warning($label . ' is not configured', [
            'expected_config' => $configKeys,
            'expected_env' => $environmentKeys,
            'provider_id' => $provider?->getId(),
        ]);

        return null;
    }

    private function logger(): ?LoggerInterface
    {
        if ($this->logger instanceof LoggerInterface) {
            return $this->logger;
        }

        if (!isset($this->loggerService) || !$this->loggerService instanceof LoggerService) {
            return null;
        }

        $this->logger = $this->loggerService->getLogger('99Food');

        return $this->logger;
    }
}
