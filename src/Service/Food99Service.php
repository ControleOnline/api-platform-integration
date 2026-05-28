<?php

namespace ControleOnline\Service;

use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Address;
use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\OrderProductQueue;
use ControleOnline\Entity\PaymentType;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\ProductUnity;
use ControleOnline\Entity\Status;
use ControleOnline\Entity\Wallet;
use ControleOnline\Service\Marketplace\Food99CatalogOperationsService;
use ControleOnline\Service\Marketplace\Food99FinancialOperationsService;
use ControleOnline\Service\Marketplace\Food99OrderOperationsService;
use ControleOnline\Service\Marketplace\Food99PeopleOperationsService;
use ControleOnline\Service\Marketplace\Food99StoreOperationsService;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationHandlerInterface;
use ControleOnline\Service\Marketplace\AbstractMarketplaceService;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationStateProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceLogisticsQuoteProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceOrderSnapshotProviderInterface;
use ControleOnline\Event\EntityChangedEvent;
use DateTime;
use DateTimeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

class Food99Service extends AbstractMarketplaceService implements
    MarketplaceIntegrationHandlerInterface,
    MarketplaceIntegrationStateProviderInterface,
    MarketplaceLogisticsQuoteProviderInterface,
    MarketplaceOrderSnapshotProviderInterface,
    EventSubscriberInterface
{
    private const APP_CONTEXT = Order::APP_FOOD99;
    private static array $authTokenCache = [];
    private const MARKETPLACE_CAPABILITY_SERVICES = [
        Food99StoreOperationsService::class,
        Food99CatalogOperationsService::class,
        Food99PeopleOperationsService::class,
        Food99FinancialOperationsService::class,
        Food99OrderOperationsService::class,
    ];
    private const ORDER_INTEGRATION_STATE_FIELDS = [
        'remote_order_state',
        'remote_delivery_status',
        'last_event_type',
        'last_event_at',
        'cancel_code',
        'cancel_reason',
        'last_action',
        'last_action_at',
        'last_action_errno',
        'last_action_message',
        'confirm_at',
        'confirm_errno',
        'confirm_message',
        'reconcile_at',
        'reconcile_errno',
        'reconcile_message',
        'reconcile_latency_ms',
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
        'delivery_locator_at',
        'delivery_locator_errno',
        'delivery_locator_message',
        'delivery_locator_last8',
        'delivery_locator_step',
        'delivery_locator_remote_order_id',
        'delivery_locator_shop_id',
    ];

    protected function getMarketplaceApp(): string
    {
        return self::APP_CONTEXT;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntityChangedEvent::class => 'onEntityChanged',
        ];
    }

    public function integrate(Integration $integration): ?Order
    {
        return $this->resolveMarketplaceCapabilityService(Food99OrderOperationsService::class)
            ->integrate($integration);
    }

    public function getStoredIntegrationState(People $provider): array
    {
        return $this->resolveMarketplaceCapabilityService(Food99StoreOperationsService::class)
            ->getStoredIntegrationState($provider);
    }

    public function quoteDelivery(Order $order): array
    {
        return $this->resolveMarketplaceCapabilityService(Food99StoreOperationsService::class)
            ->quoteDelivery($order);
    }

    public function requestDeliveryFromQuote(Order $order): array
    {
        return $this->resolveMarketplaceCapabilityService(Food99StoreOperationsService::class)
            ->requestDeliveryFromQuote($order);
    }

    public function getOrderHomologationSnapshot(Order $order): array
    {
        return $this->resolveMarketplaceCapabilityService(Food99FinancialOperationsService::class)
            ->getOrderHomologationSnapshot($order);
    }

    public function onEntityChanged(EntityChangedEvent $event)
    {
        return $this->resolveMarketplaceCapabilityService(Food99OrderOperationsService::class)
            ->onEntityChanged($event);
    }

    public function __call(string $name, array $arguments): mixed
    {
        foreach (self::MARKETPLACE_CAPABILITY_SERVICES as $serviceClass) {
            $service = $this->resolveMarketplaceCapabilityService($serviceClass, false);
            if ($service !== null && method_exists($service, $name)) {
                return $this->invokeMarketplaceServiceMethod($service, $name, $arguments);
            }
        }

        throw new \BadMethodCallException(sprintf(
            'Unknown marketplace method "%s" on %s.',
            $name,
            self::class
        ));
    }

    private function resolveMarketplaceCapabilityService(string $serviceClass, bool $throwIfMissing = true): ?object
    {
        $service = $this->resolveMarketplaceServiceInstance($serviceClass);
        if (!is_object($service)) {
            if ($throwIfMissing) {
                throw new \RuntimeException(sprintf('Marketplace service %s is not available.', $serviceClass));
            }

            return null;
        }

        return $service;
    }

    private function callMarketplaceCapabilityMethod(string $serviceClass, string $method, array $arguments = []): mixed
    {
        $service = $this->resolveMarketplaceCapabilityService($serviceClass);

        return $this->invokeMarketplaceServiceMethod($service, $method, $arguments);
    }

    private function resolveFallbackRemoteOrderStateForDeliveryEvent(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99OrderOperationsService::class, __FUNCTION__, $arguments);
    }

    private function isReadyQueueTransition(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99OrderOperationsService::class, __FUNCTION__, $arguments);
    }

    private function areAllOrderProductQueuesReady(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99OrderOperationsService::class, __FUNCTION__, $arguments);
    }

    private function applyLocalLifecycleStatusFromRemoteState(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99OrderOperationsService::class, __FUNCTION__, $arguments);
    }

    private function handleOrderProductQueueReadyTransition(...$arguments): mixed
    {
        /** @var OrderProductQueue|null $oldQueue */
        [$oldQueue, $newQueue] = $arguments + [null, null];

        if (!$oldQueue instanceof \ControleOnline\Entity\OrderProductQueue || !$newQueue instanceof \ControleOnline\Entity\OrderProductQueue) {
            return null;
        }

        if (!$this->isReadyQueueTransition($oldQueue, $newQueue)) {
            return null;
        }

        $order = $newQueue->getOrderProduct()?->getOrder();
        if (!$order instanceof Order || $order->getApp() !== self::APP_CONTEXT) {
            return null;
        }

        $realStatus = strtolower(trim((string) ($order->getStatus()?->getRealStatus() ?? '')));
        if ($realStatus !== 'open' || !$this->areAllOrderProductQueuesReady($order)) {
            return null;
        }

        $this->performReadyAction($order);

        return null;
    }

    private function resolveFood99SettlementWallet(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99StoreOperationsService::class, __FUNCTION__, $arguments);
    }

    private function resolveFood99WebhookOnlineState(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99StoreOperationsService::class, __FUNCTION__, $arguments);
    }

    private function resolveRemoteOrderStateFromDeliveryStatus(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99OrderOperationsService::class, __FUNCTION__, $arguments);
    }

    private function resolveIncomingProductGroup(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99OrderOperationsService::class, __FUNCTION__, $arguments);
    }

    private function fetchMenuProducts(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99CatalogOperationsService::class, __FUNCTION__, $arguments);
    }

    private function resolveFood99RemoteClientId(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99PeopleOperationsService::class, __FUNCTION__, $arguments);
    }

    private function resolveFood99CustomerName(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99PeopleOperationsService::class, __FUNCTION__, $arguments);
    }

    private function extractOrderRiderName(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99OrderOperationsService::class, __FUNCTION__, $arguments);
    }

    private function extractOrderRiderPhone(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99OrderOperationsService::class, __FUNCTION__, $arguments);
    }

    private function extractOrderRiderToStoreEta(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99OrderOperationsService::class, __FUNCTION__, $arguments);
    }

    private function syncFood99CourierFromDeliveryState(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99OrderOperationsService::class, __FUNCTION__, $arguments);
    }

    private function syncFood99DeliveryOrder(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99OrderOperationsService::class, __FUNCTION__, $arguments);
    }

    private function resolveFood99QuoteDeliveryAreaMatch(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99StoreOperationsService::class, __FUNCTION__, $arguments);
    }

    private function discoveryClient(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99OrderOperationsService::class, __FUNCTION__, $arguments);
    }

    private function resolveFood99MarketplacePeople(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99FinancialOperationsService::class, __FUNCTION__, $arguments);
    }

    private function resolveFood99ProviderPaymentType(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99FinancialOperationsService::class, __FUNCTION__, $arguments);
    }

    private function resolveFood99WeeklyDueDate(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99FinancialOperationsService::class, __FUNCTION__, $arguments);
    }

    private function createFood99PayableInvoice(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99FinancialOperationsService::class, __FUNCTION__, $arguments);
    }

    private function storeOrderRemoteSnapshot(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(Food99OrderOperationsService::class, __FUNCTION__, $arguments);
    }

    private function buildLogContext(?Integration $integration = null, array $json = [], array $extra = []): array
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $info = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];
        $shop = is_array($info['shop'] ?? null) ? $info['shop'] : (is_array($data['shop'] ?? null) ? $data['shop'] : []);

        return array_merge([
            'integration_id' => $integration?->getId(),
            'logEntity' => $integration,
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

    private function resolveFood99QuoteSourceOrder(Order $order): Order
    {
        $mainOrder = $order->getMainOrder();
        if ($mainOrder instanceof Order) {
            return $mainOrder;
        }

        $mainOrderId = $order->getMainOrderId();
        if ($mainOrderId) {
            $resolved = $this->entityManager->getRepository(Order::class)->find((int) $mainOrderId);
            if ($resolved instanceof Order) {
                return $resolved;
            }
        }

        return $order;
    }

    private function resolveFood99QuotePickupAddress(Order $order, Order $sourceOrder): ?Address
    {
        $pickupAddress = $this->resolveAddressCandidate($order->getAddressOrigin());
        if ($pickupAddress instanceof Address) {
            return $pickupAddress;
        }

        $pickupAddress = $this->resolveAddressCandidate($sourceOrder->getAddressOrigin());
        if ($pickupAddress instanceof Address) {
            return $pickupAddress;
        }

        return null;
    }

    private function resolveFood99DeliveryPickupAddress(Order $order): ?Address
    {
        $pickupAddress = $this->resolveAddressCandidate($order->getAddressOrigin());
        if ($pickupAddress instanceof Address) {
            return $pickupAddress;
        }

        return $this->resolveFood99PrimaryAddress($order->getProvider());
    }

    private function resolveFood99DeliveryDropoffAddress(Order $order): ?Address
    {
        $dropoffAddress = $this->resolveAddressCandidate($order->getAddressDestination());
        if ($dropoffAddress instanceof Address) {
            return $dropoffAddress;
        }

        return $this->resolveFood99PrimaryAddress($order->getClient());
    }

    private function resolveFood99PrimaryAddress(?People $people): ?Address
    {
        if (!$people instanceof People) {
            return null;
        }

        foreach ($people->getAddress() as $address) {
            $resolvedAddress = $this->resolveAddressCandidate($address);
            if ($resolvedAddress instanceof Address) {
                return $resolvedAddress;
            }
        }

        return null;
    }

    private function resolveFood99QuoteDropoffAddress(Order $order, Order $sourceOrder): ?Address
    {
        $dropoffAddress = $this->resolveAddressCandidate($order->getAddressDestination());
        if ($dropoffAddress instanceof Address) {
            return $dropoffAddress;
        }

        $dropoffAddress = $this->resolveAddressCandidate($sourceOrder->getAddressDestination());

        return $dropoffAddress instanceof Address ? $dropoffAddress : null;
    }

    private function getStoredFood99QuoteState(Order $order): array
    {
        $otherInformations = $order->getOtherInformations(true);
        if (is_object($otherInformations)) {
            $otherInformations = (array) $otherInformations;
        }

        if (!is_array($otherInformations)) {
            return [];
        }

        $logistics = $otherInformations['logistics'] ?? [];

        return is_array($logistics) ? $logistics : [];
    }

    private function persistFood99QuoteState(Order $order, array $storedState, array $logisticsState): void
    {
        $otherInformations = $order->getOtherInformations(true);
        if (is_object($otherInformations)) {
            $otherInformations = (array) $otherInformations;
        }

        if (!is_array($otherInformations)) {
            $otherInformations = [];
        }

        $otherInformations[self::APP_CONTEXT] = $storedState;
        $otherInformations['logistics'] = $logisticsState;

        $order->setOtherInformations($otherInformations);
        $order->setAlterDate(new DateTime('now'));
        if (isset($logisticsState['price']) && is_numeric($logisticsState['price'])) {
            $order->setPrice((float) $logisticsState['price']);
        }

        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }

    private function persistFood99QuoteFailure(Order $order, string $quoteState, string $message, array $extraState = []): array
    {
        $now = date('Y-m-d H:i:s');
        $storedState = array_merge($this->getStoredFood99QuoteState($order), $extraState, [
            'quote_state' => $quoteState,
            'quote_message' => $message,
            'quote_requested_at' => $extraState['quote_requested_at'] ?? $now,
            'quote_updated_at' => $now,
            'price' => null,
            'tracking_url' => null,
            'remote_order_id' => $extraState['remote_order_id'] ?? null,
        ]);
        $this->persistFood99QuoteState($order, $storedState, array_merge([
            'flow' => 'quote',
            'provider_key' => 'food99',
            'provider_label' => '99 Food',
            'quote_state' => $quoteState,
            'quote_message' => $message,
            'quote_requested_at' => $storedState['quote_requested_at'],
            'quote_updated_at' => $now,
            'price' => null,
            'tracking_url' => null,
            'quote_response' => $extraState['quote_response'] ?? null,
        ], $extraState));

        return [
            'errno' => $extraState['quote_status'] ?? 501,
            'errmsg' => $message,
            'data' => [
                'order_id' => $order->getId(),
                'quote_state' => $quoteState,
                'quote_message' => $message,
            ],
        ];
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

    private function getFood99BaseUrl(): string
    {
        return 'https://openapi.99food.com';
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

        self::$logger->warning('Food99 app_shop_id could not be resolved', [
            'provider_id' => $provider?->getId(),
            'expected_provider_value' => 'People.id',
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
        $storeService = $this->resolveMarketplaceCapabilityService(Food99StoreOperationsService::class, false);
        if (!$storeService instanceof Food99StoreOperationsService) {
            return [
                'errno' => 1,
                'errmsg' => 'Servico de cancelamento da 99Food nao esta disponivel.',
                'data' => [
                    'delivery_type' => '',
                    'delivery_label' => 'Indefinido',
                    'reasons' => [],
                ],
            ];
        }

        $result = $storeService->getOrderCancelReasons($order);

        return is_array($result) ? $result : [
            'errno' => 1,
            'errmsg' => 'Servico de cancelamento da 99Food retornou uma resposta invalida.',
            'data' => [
                'delivery_type' => '',
                'delivery_label' => 'Indefinido',
                'reasons' => [],
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

        $resolvedReasonId = $reasonId ?: 1080;
        $storeService = $this->resolveMarketplaceCapabilityService(Food99StoreOperationsService::class, false);
        if (!$storeService instanceof Food99StoreOperationsService) {
            return $this->persistOrderActionResult(
                $order,
                'cancel',
                $this->buildUnavailableOrderActionResponse('O service de catalogo da 99Food nao esta disponivel.')
            );
        }

        $cancelReasonsResponse = $storeService->getOrderCancelReasons($order);
        $cancelReasons = is_array($cancelReasonsResponse['data']['reasons'] ?? null)
            ? $cancelReasonsResponse['data']['reasons']
            : [];
        $definition = null;
        foreach ($cancelReasons as $reasonDefinition) {
            if ((int) ($reasonDefinition['reason_id'] ?? 0) === $resolvedReasonId) {
                $definition = $reasonDefinition;
                break;
            }
        }

        if (!$definition) {
            return $this->persistOrderActionResult(
                $order,
                'cancel',
                $this->buildUnavailableOrderActionResponse('Motivo de cancelamento da 99Food invalido.')
            );
        }

        if (!($definition['applicable'] ?? false)) {
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

    private function getFood99ExtraDataValue(string $entityName, int $entityId, string $fieldName = 'code'): ?string
    {
        if ($entityId <= 0) {
            return null;
        }

        return $this->extraDataService->getExtraDataValue(
            self::APP_CONTEXT,
            $entityName,
            $entityId,
            $fieldName
        );
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

        $this->extraDataService->upsertExtraDataValue(
            self::APP_CONTEXT,
            $entityName,
            $entityId,
            $fieldName,
            $value,
            $fieldType,
            self::APP_CONTEXT
        );

        return $this->extraDataService->getExtraDataValue(
            self::APP_CONTEXT,
            $entityName,
            $entityId,
            $fieldName,
            $fieldType
        );
    }

    private function findFood99EntityByExtraData(
        string $entityName,
        string $fieldName,
        mixed $value,
        string $entityClass
    ): ?object {
        $normalizedValue = trim((string) $value);
        if ($normalizedValue === '') {
            return null;
        }

        $entity = $this->extraDataService->getEntityByExtraData(
            self::APP_CONTEXT,
            $fieldName,
            $normalizedValue,
            $entityClass
        );

        return is_object($entity) ? $entity : null;
    }

    private function findFood99OrderByLegacyAwareExtraData(string $fieldName, mixed $value): ?Order
    {
        $normalizedValue = trim((string) $value);
        if ($normalizedValue === '') {
            return null;
        }

        $entity = $this->extraDataService->getEntityByExtraData(
            self::APP_CONTEXT,
            $fieldName,
            $normalizedValue,
            Order::class
        );

        if (!$entity instanceof Order || $entity->getApp() !== self::APP_CONTEXT) {
            return null;
        }

        return $entity;
    }

    private function getFood99OrderExtraDataValue(int $entityId, string $fieldName): ?string
    {
        if ($entityId <= 0) {
            return null;
        }

        return $this->extraDataService->getExtraDataValue(
            self::APP_CONTEXT,
            'Order',
            $entityId,
            $fieldName
        );
    }

    private function decodeOrderOtherInformationsValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            $normalized = json_decode(json_encode($value), true);

            return is_array($normalized) ? $normalized : [];
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
            if ($otherInformations === []) {
                $otherInformations = $this->decodeOrderOtherInformationsValue($order->getOtherInformations());
            }
        } catch (\Throwable) {
            $otherInformations = [];
        }

        return $otherInformations;
    }

    private function extractOrderIntegrationStateFromOtherInformations(Order $order): array
    {
        $otherInformations = $this->getDecodedOrderOtherInformations($order);
        $payload = null;

        $candidate = $otherInformations[self::APP_CONTEXT] ?? null;
        if (is_array($candidate)) {
            $payload = $candidate;
        }

        if (is_string($candidate)) {
            $decodedCandidate = $this->decodeOrderOtherInformationsValue($candidate);
            if (!empty($decodedCandidate)) {
                $payload = $decodedCandidate;
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

        for ($depth = 0; $depth < 32; $depth++) {
            $candidate = $normalizedPayload[self::APP_CONTEXT] ?? null;
            if (!is_array($candidate)) {
                break;
            }

            if ($candidate === $normalizedPayload) {
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
    ): ?Order {
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

        $storeService = $this->resolveMarketplaceCapabilityService(Food99StoreOperationsService::class, false);
        if (is_object($storeService)) {
            $this->invokeMarketplaceServiceMethod($storeService, 'persistProviderIntegrationState', [$provider, $fields]);
        }
    }

    private function waitForExistingIntegratedOrder(
        string $orderId,
        string $orderCode,
        int $attempts = 5,
        int $sleepMicroseconds = 250000,
        bool $allowCodeFallback = true
    ): ?Order {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $existing = $this->findExistingIntegratedOrder($orderId, $orderCode, $allowCodeFallback);
            if ($existing instanceof Order) {
                return $existing;
            }

            usleep($sleepMicroseconds);
        }

        return null;
    }

    private function resolveOrderClient(People $provider, array $address, array $payload, string $orderId): People
    {
        $client = $this->discoveryClient($address, $payload, $provider);
        if ($client instanceof People) {
            $this->peopleService->discoveryLink($provider, $client, 'client');
            return $client;
        }

        $fallbackName = $this->resolveFood99CustomerName($address);
        $clientCode = $this->resolveFood99RemoteClientId($address, $payload);

        if ($fallbackName !== '') {
            $client = $this->findFood99ClientByAddressAndName($address, $fallbackName);
            if ($client instanceof People) {
                self::$logger->info('Food99 customer matched by exact name and address after missing remote code', [
                    'order_id' => $orderId,
                    'client_id' => $client->getId(),
                    'client_code' => $clientCode,
                    'address_keys' => array_keys($address),
                ]);

                $this->syncFood99ClientData($client, $provider, $address, $clientCode);
                $this->peopleService->discoveryLink($provider, $client, 'client');

                return $client;
            }
        }

        self::$logger->warning('Food99 order received without an exact mapped customer code; creating a fresh customer record after exact name/address lookup failed', [
            'order_id' => $orderId,
            'client_code' => $clientCode,
            'address_keys' => array_keys($address),
        ]);

        $client = $this->peopleService->discoveryPeople(
            null,
            null,
            [],
            $fallbackName !== '' ? $fallbackName : 'Cliente Food99',
            'F'
        );

        $this->syncFood99ClientData($client, $provider, $address, $clientCode);

        return $client;
    }

    private function persistOrderIntegrationState(Order $order, array $fields): void
    {
        $normalizedFields = [];
        foreach ($fields as $fieldName => $value) {
            $normalizedFieldName = trim((string) $fieldName);
            if ($normalizedFieldName === '' || !in_array($normalizedFieldName, self::ORDER_INTEGRATION_STATE_FIELDS, true)) {
                continue;
            }

            $normalizedFields[$normalizedFieldName] = $value;
        }

        if ($normalizedFields === []) {
            return;
        }

        $this->mergeEntityOtherInformations($order, self::APP_CONTEXT, $normalizedFields);
    }

    public function getStoredOrderIntegrationState(Order $order): array
    {
        $this->init();

        $fallbackState = $this->extractOrderIntegrationStateFromOtherInformations($order);
        $state = $fallbackState;

        $orderId = (int) $order->getId();
        $legacyState = [
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

        foreach ($legacyState as $key => $value) {
            if (array_key_exists($key, $state) && $state[$key] !== null && $state[$key] !== '') {
                continue;
            }

            $state[$key] = $value;
        }

        $state = array_merge($state, $this->resolveOrderDeliveryFlags($state));
        $state['allows_manual_delivery_completion'] = $this->resolveAllowsManualDeliveryCompletion($state);

        return $state;
    }

    private function resolveBestStoredOrderPayload(Order $order): array
    {
        $otherInformations = $this->getDecodedOrderOtherInformations($order);
        $candidate = $otherInformations[self::APP_CONTEXT] ?? null;
        if (is_string($candidate)) {
            $candidate = $this->decodeOrderOtherInformationsValue($candidate);
        }

        if (!is_array($candidate) || empty($candidate)) {
            return [];
        }

        $latestEventType = $this->normalizeIncomingFood99Value(
            $candidate['latest_event_type'] ?? $candidate['latestEventType'] ?? null
        );

        return $this->resolveBestPayloadFromStoredOrderCandidate($candidate, $latestEventType);
    }

    private function resolveBestPayloadFromStoredOrderCandidate(array $candidate, string $preferredEventType = ''): array
    {
        $payload = $this->unwrapStoredOrderPayload($candidate);
        if (!is_array($payload) || empty($payload)) {
            return [];
        }

        $bestPayload = $payload;
        $bestScore = $this->scoreFood99StoredPayload($payload);
        $containerLatestEventType = $this->normalizeIncomingFood99Value($payload['latest_event_type'] ?? null);
        $eventCandidateKeys = array_values(array_unique(array_filter([
            $preferredEventType,
            $containerLatestEventType,
            'orderDetailSync',
            'orderNew',
            'deliveryStatus',
            'orderReady',
            'orderConfirm',
            'orderFinish',
        ], static fn(string $value): bool => $value !== '' && strtolower(trim($value)) !== 'ifood')));

        foreach ($eventCandidateKeys as $eventCandidateKey) {
            $eventPayload = $payload[$eventCandidateKey] ?? null;
            if (is_string($eventPayload)) {
                $eventPayload = $this->decodeOrderOtherInformationsValue($eventPayload);
            }

            if (!is_array($eventPayload) || empty($eventPayload)) {
                continue;
            }

            $eventPayload = $this->unwrapStoredOrderPayload($eventPayload);
            if (!is_array($eventPayload) || empty($eventPayload)) {
                continue;
            }

            $score = $this->scoreFood99StoredPayload($eventPayload);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestPayload = $eventPayload;
            }
        }

        return $bestScore > 0 ? $bestPayload : [];
    }

    private function scoreFood99StoredPayload(array $payload): int
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $orderInfo = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];
        $price = is_array($orderInfo['price'] ?? null)
            ? $orderInfo['price']
            : (is_array($data['price'] ?? null) ? $data['price'] : []);
        $items = is_array($orderInfo['order_items'] ?? null)
            ? $orderInfo['order_items']
            : (is_array($data['order_items'] ?? null) ? $data['order_items'] : []);
        $receiveAddress = is_array($orderInfo['receive_address'] ?? null)
            ? $orderInfo['receive_address']
            : (is_array($data['receive_address'] ?? null) ? $data['receive_address'] : []);
        $promotions = is_array($orderInfo['promotions'] ?? null)
            ? $orderInfo['promotions']
            : (is_array($data['promotions'] ?? null) ? $data['promotions'] : []);

        $score = 0;

        if ($orderInfo !== []) {
            $score += 10;
        }

        if ($price !== []) {
            $score += 40;
        }

        if ($items !== []) {
            $score += 20;
        }

        if ($receiveAddress !== []) {
            $score += 10;
        }

        if ($promotions !== []) {
            $score += 10;
        }

        if (isset($price['order_price']) || isset($price['customer_need_paying_money']) || isset($price['real_pay_price'])) {
            $score += 40;
        }

        if (isset($orderInfo['pay_type']) || isset($orderInfo['pay_method']) || isset($orderInfo['delivery_type'])) {
            $score += 20;
        }

        if (isset($data['order_id']) || isset($orderInfo['order_id'])) {
            $score += 1;
        }

        return $score;
    }

    private function normalizeFood99Money(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return round(((float) $value) / 100, 2);
    }

    private function extractFood99StoredSnapshotSection(array $payload, string $section): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $orderInfo = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];

        foreach ([$payload[$section] ?? null, $data[$section] ?? null, $orderInfo[$section] ?? null] as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                return $candidate;
            }

            if (is_string($candidate)) {
                $decodedCandidate = $this->decodeOrderOtherInformationsValue($candidate);
                if (is_array($decodedCandidate) && $decodedCandidate !== []) {
                    return $decodedCandidate;
                }
            }
        }

        return [];
    }

    private function resolveFood99SnapshotMoneyValue(array $source, mixed $fallback, string ...$keys): float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }

            $candidate = $source[$key];
            if ($candidate === null || $candidate === '') {
                continue;
            }

            return $this->normalizeFood99Money($candidate);
        }

        return round((float) ($fallback ?? 0), 2);
    }

    private function resolveFood99SnapshotBooleanValue(array $source, ?bool $fallback, string ...$keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }

            $candidate = $this->normalizeFood99Boolean($source[$key]);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return $fallback ?? false;
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

    private function buildPromotionFundingBreakdown(array $promotions): array
    {
        $breakdown = [
            'store_total' => 0.0,
            'platform_total' => 0.0,
            'store_delivery_total' => 0.0,
            'platform_delivery_total' => 0.0,
            'store_non_delivery_total' => 0.0,
            'platform_non_delivery_total' => 0.0,
        ];

        foreach ($promotions as $promotion) {
            if (!is_array($promotion)) {
                continue;
            }

            $promotionType = (int) ($promotion['promo_type'] ?? 0);
            $discountAmount = $this->normalizeFood99Money($promotion['promo_discount'] ?? null);
            $storeSubsidyAmount = $this->normalizeFood99Money($promotion['shop_subside_price'] ?? null);
            $platformSubsidyAmount = round(max(0, $discountAmount - $storeSubsidyAmount), 2);

            $breakdown['store_total'] += $storeSubsidyAmount;
            $breakdown['platform_total'] += $platformSubsidyAmount;

            if ($promotionType === 3) {
                $breakdown['store_delivery_total'] += $storeSubsidyAmount;
                $breakdown['platform_delivery_total'] += $platformSubsidyAmount;
                continue;
            }

            $breakdown['store_non_delivery_total'] += $storeSubsidyAmount;
            $breakdown['platform_non_delivery_total'] += $platformSubsidyAmount;
        }

        foreach ($breakdown as $key => $value) {
            $breakdown[$key] = round((float) $value, 2);
        }

        return $breakdown;
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

}
