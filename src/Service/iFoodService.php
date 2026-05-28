<?php

namespace ControleOnline\Service;

use ControleOnline\Service\AddressService;
use ControleOnline\Entity\Address;
use ControleOnline\Entity\Category;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Invoice;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\OrderProductQueue;
use ControleOnline\Entity\PaymentType;
use ControleOnline\Entity\File;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\ProductUnity;
use ControleOnline\Entity\Status;
use ControleOnline\Entity\User;
use ControleOnline\Entity\Wallet;
use ControleOnline\Entity\WalletPaymentType;
use ControleOnline\Service\Marketplace\AbstractMarketplaceService;
use ControleOnline\Service\Marketplace\IfoodCatalogOperationsService;
use ControleOnline\Service\Marketplace\IfoodFinancialOperationsService;
use ControleOnline\Service\Marketplace\IfoodOrderOperationsService;
use ControleOnline\Service\Marketplace\IfoodPeopleOperationsService;
use ControleOnline\Service\Marketplace\IfoodStoreOperationsService;
use ControleOnline\Service\Client\WebsocketClient;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationHandlerInterface;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationStateProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceLogisticsQuoteProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceOrderSnapshotProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use ControleOnline\Service\LoggerService;
use DateTime;
use ControleOnline\Event\EntityChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class iFoodService extends AbstractMarketplaceService implements
    MarketplaceIntegrationHandlerInterface,
    MarketplaceIntegrationStateProviderInterface,
    MarketplaceLogisticsQuoteProviderInterface,
    MarketplaceOrderSnapshotProviderInterface,
    EventSubscriberInterface
{
    private const APP_CONTEXT = Order::APP_IFOOD;
    private const API_BASE_URL = 'https://merchant-api.ifood.com.br';
    private const SELF_DELIVERY_CONFIRMATION_URL = 'https://confirmacao-entrega-propria.ifood.com.br/';
    private const MAX_IMAGE_UPLOAD_BYTES = 5242880; // 5MB
    private const IMAGE_UPLOAD_PAYLOAD_MARGIN_BYTES = 512;
    private const IMAGE_UPLOAD_MAX_DIMENSION = 3000;
    private const CATALOG_CONCURRENT_RETRY_DELAYS_US = [500000, 1500000, 3000000, 5000000];
    private const MARKETPLACE_CAPABILITY_SERVICES = [
        IfoodStoreOperationsService::class,
        IfoodCatalogOperationsService::class,
        IfoodPeopleOperationsService::class,
        IfoodFinancialOperationsService::class,
        IfoodOrderOperationsService::class,
    ];
    private static array $catalogImagePathCache = [];

    protected function getMarketplaceApp(): string
    {
        return self::APP_CONTEXT;
    }

    protected function resolveMarketplacePeople(): ?People
    {
        if (!isset($this->peopleService)) {
            return null;
        }

        return $this->peopleService->discoveryPeople(
            '14380200000121',
            null,
            null,
            'Ifood.com Agência de Restaurantes Online S.A',
            'J'
        );
    }

    private function getAccessToken(): ?string
    {
        return $this->ifoodClient->getAccessToken();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntityChangedEvent::class => 'onEntityChanged',
        ];
    }

    public function getStoredIntegrationState(People $provider): array
    {
        return $this->resolveMarketplaceCapabilityService(IfoodStoreOperationsService::class)
            ->getStoredIntegrationState($provider);
    }

    public function quoteDelivery(Order $order): array
    {
        return $this->resolveMarketplaceCapabilityService(IfoodStoreOperationsService::class)
            ->quoteDelivery($order);
    }

    public function requestDeliveryFromQuote(Order $order): array
    {
        return $this->resolveMarketplaceCapabilityService(IfoodStoreOperationsService::class)
            ->requestDeliveryFromQuote($order);
    }

    public function getStoredOrderIntegrationState(Order $order): array
    {
        return $this->resolveMarketplaceCapabilityService(IfoodOrderOperationsService::class)
            ->getStoredOrderIntegrationState($order);
    }

    public function getOrderHomologationSnapshot(Order $order): array
    {
        return $this->resolveMarketplaceCapabilityService(IfoodOrderOperationsService::class)
            ->getOrderHomologationSnapshot($order);
    }

    public function onEntityChanged(EntityChangedEvent $event)
    {
        return $this->resolveMarketplaceCapabilityService(IfoodStoreOperationsService::class)
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

    private function resolveWebhookMerchantStatus(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(IfoodStoreOperationsService::class, __FUNCTION__, $arguments);
    }

    private function isStoreStatusWebhookEvent(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(IfoodStoreOperationsService::class, __FUNCTION__, $arguments);
    }

    private function getStoredIfoodQuoteState(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(IfoodStoreOperationsService::class, __FUNCTION__, $arguments);
    }

    private function buildIfoodShippingAddressPayload(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(IfoodStoreOperationsService::class, __FUNCTION__, $arguments);
    }

    private function validateIfoodQuoteRoute(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(IfoodStoreOperationsService::class, __FUNCTION__, $arguments);
    }

    private function persistIfoodQuoteState(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(IfoodStoreOperationsService::class, __FUNCTION__, $arguments);
    }

    private function resolveIfoodCatalogCategoryId(
        string $merchantId,
        string $catalogId,
        string $categoryName,
        array &$remoteCategoriesByName,
        int $sequence = 0,
        int $localCategoryId = 0,
        string $storedIfoodId = ''
    ): mixed {
        return $this->callMarketplaceCapabilityMethod(
            IfoodCatalogOperationsService::class,
            __FUNCTION__,
            [
                $merchantId,
                $catalogId,
                $categoryName,
                &$remoteCategoriesByName,
                $sequence,
                $localCategoryId,
                $storedIfoodId,
            ]
        );
    }

    private function normalizeImageMimeType(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(IfoodCatalogOperationsService::class, __FUNCTION__, $arguments);
    }

    private function isIfoodUploadImageWithinLimits(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(IfoodCatalogOperationsService::class, __FUNCTION__, $arguments);
    }

    private function fetchCatalogModifierRows(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(IfoodCatalogOperationsService::class, __FUNCTION__, $arguments);
    }

    private function buildIfoodCatalogModifierPayload(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(IfoodCatalogOperationsService::class, __FUNCTION__, $arguments);
    }

    private function findIfoodCatalogRemoteItemByProductFallback(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(IfoodCatalogOperationsService::class, __FUNCTION__, $arguments);
    }

    private function expandCatalogProductsWithModifierDescendants(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(IfoodCatalogOperationsService::class, __FUNCTION__, $arguments);
    }

    private function upsertIfoodCatalogItemV2(...$arguments): mixed
    {
        return $this->callMarketplaceCapabilityMethod(IfoodCatalogOperationsService::class, __FUNCTION__, $arguments);
    }

    // PONTO DE ENTRADA DO WEBHOOK
    // Recebe webhook do iFood, decodifica JSON e roteia para ação correta (PLACED ou CANCELLED)
    public function integrate(Integration $integration): ?Order
    {
        $this->init();

        $event = $this->resolveIncomingEvent($integration);
        if (!$event) {
            return null;
        }

        $eventCode = $this->resolveEventCode($event);
        if ($eventCode === 'KEEPALIVE') {
            return null;
        }

        if ($this->isStoreStatusWebhookEvent($event, $eventCode)) {
            $this->syncStoreStatusWebhook($event, $integration);
            return null;
        }

        $orderId = $this->normalizeString($event['orderId'] ?? null);
        if ($orderId === '') {
            self::$logger->warning('iFood event ignored because orderId is missing', $this->buildLogContext($integration, $event, [
                'event_code' => $eventCode,
            ]));
            return null;
        }

        $order = $this->findOrderByExternalId($orderId);
        $orderAlreadyExisted = $order instanceof Order;
        if (!$order instanceof Order) {
            if (!$this->shouldCreateOrderFromEvent($eventCode)) {
                self::$logger->warning('iFood event ignored because local order does not exist and event should not create a new order', $this->buildLogContext($integration, $event, [
                    'event_code' => $eventCode,
                    'order_id' => $orderId,
                ]));
                return null;
            }

            $order = $this->addOrder($event);
        }

        if (!$order instanceof Order) {
            self::$logger->warning('iFood event ignored because local order could not be resolved', $this->buildLogContext($integration, $event, [
                'event_code' => $eventCode,
                'order_id' => $orderId,
            ]));
            return null;
        }

        $this->appendOrderEventPayload($order, $eventCode, $event);
        $this->persistIncomingEventState($order, $integration, $event);

        self::$logger->info('iFood integration resolved local order for event', $this->buildLogContext($integration, $event, [
            'order_id' => $orderId,
            'local_order_id' => $order->getId(),
            'event_code' => $eventCode,
            'order_already_existed' => $orderAlreadyExisted,
        ]));

        if ($orderAlreadyExisted) {
            $orderDetails = $this->refreshOrderCoreDataFromEvent($order, $event);
            $this->resumePendingEntryFlowIfNeeded($order, $event, $eventCode, $orderDetails);
        }

        $this->applyOperationalStatusForRemoteState(
            $order,
            $this->resolveRemoteOrderStateByEventCode($eventCode)
        );

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    private function shouldCreateOrderFromEvent(string $eventCode): bool
    {
        $normalized = strtoupper($this->normalizeString($eventCode));
        if ($normalized === '' || $normalized === 'KEEPALIVE') {
            return false;
        }

        return $this->isEntryEventCode($normalized);
    }

    private function isEntryEventCode(string $eventCode): bool
    {
        $normalized = strtoupper($this->normalizeString($eventCode));

        return in_array($normalized, [
            'PLACED',
            'ORDER_CREATED',
            'CREATED',
            'PENDING',
            'ORDER_PENDING',
        ], true);
    }

    private function resolveIncomingEvent(Integration $integration): ?array
    {
        $payload = json_decode((string) $integration->getBody(), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            self::$logger->warning('iFood payload ignored because JSON is invalid', $this->buildLogContext($integration, [], [
                'json_error' => json_last_error_msg(),
            ]));
            return null;
        }

        if (array_is_list($payload)) {
            foreach ($payload as $event) {
                if (is_array($event)) {
                    return $event;
                }
            }

            return null;
        }

        return $payload;
    }

    private function buildLogContext(?Integration $integration = null, array $event = [], array $extra = []): array
    {
        $meta = $event !== [] ? $this->extractWebhookMeta($event) : [];
        $orderId = $this->normalizeString($event['orderId'] ?? ($meta['order_id'] ?? null));
        $merchantId = $this->normalizeString($event['merchantId'] ?? ($meta['shop_id'] ?? null));
        $eventId = $this->normalizeString($meta['event_id'] ?? null);

        return array_merge([
            'integration_id' => $integration?->getId(),
            'logEntity' => $integration,
            'event_code' => $event !== [] ? $this->resolveEventCode($event) : null,
            'order_id' => $orderId !== '' ? $orderId : null,
            'merchant_id' => $merchantId !== '' ? $merchantId : null,
            'webhook_event_id' => $eventId !== '' ? $eventId : null,
        ], $extra);
    }

    private function resolveEventCode(array $event): string
    {
        $code = $event['fullCode']
            ?? $event['code']
            ?? $event['type']
            ?? $event['eventType']
            ?? ($event['__webhook']['event_type'] ?? null);

        return strtoupper($this->normalizeString($code));
    }

    private function isCancellationEventCode(string $eventCode): bool
    {
        $normalized = strtoupper($this->normalizeString($eventCode));
        return in_array($normalized, [
            'CANCELLED',
            'CANCELED',
            'ORDER_CANCELLED',
            'ORDER_CANCELED',
            'ORDER_CANCELLED_BY_CUSTOMER',
            'ORDER_CANCELED_BY_CUSTOMER',
            'CANCELLATION_REQUESTED',
            'ORDER_CANCELLATION_REQUESTED',
        ], true);
    }

    private function isConclusionEventCode(string $eventCode): bool
    {
        $normalized = strtoupper($this->normalizeString($eventCode));
        return in_array($normalized, [
            'CONCLUDED',
            'ORDER_CONCLUDED',
            'ORDER_FINISHED',
            'DELIVERY_CONCLUDED',
        ], true);
    }

    private function findOrderByExternalId(string $orderId): ?Order
    {
        if ($orderId === '') {
            return null;
        }

        $order = $this->findEntityByExtraData('Order', 'code', $orderId, Order::class);
        if ($order instanceof Order) {
            return $order;
        }

        $order = $this->findEntityByExtraData('Order', 'id', $orderId, Order::class);
        if ($order instanceof Order) {
            return $order;
        }

        return null;
    }

    private function orderHasProducts(Order $order): bool
    {
        return $order->getOrderProducts()->count() > 0;
    }

    private function orderHasInvoices(Order $order): bool
    {
        return $order->getInvoice()->count() > 0;
    }

    private function shouldAttemptAutoConfirmEntry(Order $order, string $eventCode): bool
    {
        if (!$this->isEntryEventCode($eventCode)) {
            return false;
        }

        $currentRealStatus = strtolower(trim((string) ($order->getStatus()?->getRealStatus() ?? '')));
        $currentStatusName = strtolower(trim((string) ($order->getStatus()?->getStatus() ?? '')));

        return $currentRealStatus === 'open' && $currentStatusName === 'open';
    }

    private function resolveOrderDetailsFromEvent(string $orderId, array $event, ?Order $order = null): array
    {
        $eventOrderDetails = is_array($event['order'] ?? null) ? $event['order'] : [];
        $otherInformations = $order instanceof Order ? $this->getDecodedOrderOtherInformations($order) : [];
        $storedOrderDetails = $order instanceof Order
            ? $this->findStoredIfoodOrderDetailsFromContext($otherInformations)
            : [];
        $fetchedOrderDetails = [];
        $orderDetails = [];

        if ($eventOrderDetails !== []) {
            $orderDetails = $storedOrderDetails !== []
                ? $this->mergeIfoodOrderDetails($storedOrderDetails, $eventOrderDetails)
                : $eventOrderDetails;
        } elseif ($storedOrderDetails !== []) {
            $orderDetails = $storedOrderDetails;
        } else {
            $fetchedOrderDetails = $this->fetchOrderDetails($orderId);
            if (is_array($fetchedOrderDetails)) {
                $orderDetails = $fetchedOrderDetails;
            }
        }

        if ($order instanceof Order && $orderDetails !== []) {
            $this->persistResolvedIfoodOrderDetails($order, $event, $orderDetails, $otherInformations);
        }

        self::$logger->info('iFood order details resolved for integration step', [
            'order_id' => $orderId,
            'has_event_snapshot' => $eventOrderDetails !== [],
            'has_stored_snapshot' => $storedOrderDetails !== [],
            'has_fetched_details' => is_array($fetchedOrderDetails) && $fetchedOrderDetails !== [],
            'resolved_customer_id' => $this->normalizeString($orderDetails['customer']['id'] ?? null),
            'resolved_customer_document' => $this->normalizeString($orderDetails['customer']['documentNumber'] ?? null),
            'resolved_delivery_address' => $this->normalizeString($orderDetails['delivery']['deliveryAddress']['formattedAddress'] ?? null),
            'resolved_delivery_city' => $this->normalizeString($orderDetails['delivery']['deliveryAddress']['city'] ?? null),
            'resolved_delivery_state' => $this->normalizeString($orderDetails['delivery']['deliveryAddress']['state'] ?? null),
            'resolved_delivery_postal_code' => $this->normalizeString($orderDetails['delivery']['deliveryAddress']['postalCode'] ?? null),
        ]);

        return is_array($orderDetails) ? $orderDetails : [];
    }

    private function fetchOrderDetails(string $orderId): ?array
    {
        $service = $this->resolveMarketplaceServiceInstance(IfoodStoreOperationsService::class);
        if (!is_object($service)) {
            return null;
        }

        $details = $this->invokeMarketplaceServiceMethod($service, __FUNCTION__, [$orderId]);

        return is_array($details) ? $details : null;
    }

    private function getIfoodContextOtherInformations(Order $order): array
    {
        $otherInformations = $this->getDecodedOrderOtherInformations($order);
        if ($otherInformations === []) {
            return [];
        }

        foreach ([self::$app, strtolower(self::$app), strtoupper(self::$app)] as $contextKey) {
            $context = $this->decodeOrderOtherInformationsValue($otherInformations[$contextKey] ?? null);
            if ($context !== []) {
                return $context;
            }
        }

        return [];
    }

    private function looksLikeIfoodOrderPayload(array $payload): bool
    {
        foreach (['displayId', 'delivery', 'items', 'payments', 'total', 'customer', 'orderType', 'orderTiming'] as $fieldName) {
            if (!array_key_exists($fieldName, $payload)) {
                continue;
            }

            $fieldValue = $payload[$fieldName];
            if (is_array($fieldValue)) {
                if ($fieldValue !== []) {
                    return true;
                }

                continue;
            }

            if ($this->normalizeString($fieldValue) !== '') {
                return true;
            }
        }

        return false;
    }

    private function extractStoredIfoodOrderDetails(mixed $candidate): array
    {
        $payload = $this->decodeOrderOtherInformationsValue($candidate);
        if ($payload === []) {
            return [];
        }

        $nestedOrder = $this->decodeOrderOtherInformationsValue($payload['order'] ?? null);
        if ($nestedOrder !== []) {
            return $nestedOrder;
        }

        return $this->looksLikeIfoodOrderPayload($payload) ? $payload : [];
    }

    private function findStoredIfoodOrderDetailsFromContext(array $otherInformations): array
    {
        $context = [];
        foreach ([self::$app, strtolower(self::$app), strtoupper(self::$app)] as $contextKey) {
            $candidateContext = $this->decodeOrderOtherInformationsValue($otherInformations[$contextKey] ?? null);
            if ($candidateContext !== []) {
                $context = $candidateContext;
                break;
            }
        }

        if ($context === []) {
            return [];
        }

        $candidateKeys = [];
        $latestEventType = $this->normalizeString($context['latest_event_type'] ?? null);
        if ($latestEventType !== '') {
            $candidateKeys[] = $latestEventType;
        }

        foreach ($context as $key => $value) {
            if ($key === 'latest_event_type' || in_array($key, $candidateKeys, true)) {
                continue;
            }

            $candidateKeys[] = $key;
        }

        foreach ($candidateKeys as $candidateKey) {
            $storedDetails = $this->extractStoredIfoodOrderDetails($context[$candidateKey] ?? null);
            if ($storedDetails !== []) {
                return $storedDetails;
            }
        }

        return [];
    }

    private function findStoredIfoodOrderDetails(Order $order): array
    {
        return $this->findStoredIfoodOrderDetailsFromContext($this->getDecodedOrderOtherInformations($order));
    }

    private function persistResolvedIfoodOrderDetails(Order $order, array $event, array $orderDetails, array $otherInformations = []): void
    {
        if ($otherInformations === []) {
            $otherInformations = $this->getDecodedOrderOtherInformations($order);
        }

        $context = $this->decodeOrderOtherInformationsValue($otherInformations[self::$app] ?? null);
        if ($context === []) {
            $context = [];
        }

        $eventKey = $this->resolveEventCode($event);
        if ($eventKey === '') {
            $eventKey = $this->normalizeString($context['latest_event_type'] ?? null);
        }

        if ($eventKey === '') {
            return;
        }

        if (!is_array($context[$eventKey] ?? null)) {
            $context[$eventKey] = [];
        }

        $context[$eventKey]['order'] = $orderDetails;
        $context[$eventKey]['order_details_cached_at'] = date('Y-m-d H:i:s');
        $context['latest_event_type'] = $eventKey;

        $otherInformations[self::$app] = $context;
        $order->setOtherInformations($otherInformations);
        $this->entityManager->persist($order);
    }

    private function resumePendingEntryFlowIfNeeded(Order $order, array $event, string $eventCode, array $orderDetails = []): void
    {
        if (!$this->isEntryEventCode($eventCode)) {
            return;
        }

        $orderId = $this->normalizeString($event['orderId'] ?? null);
        if ($orderId === '') {
            return;
        }

        if (!$orderDetails) {
            $orderDetails = $this->resolveOrderDetailsFromEvent($orderId, $event, $order);
        }

        if (!$orderDetails) {
            return;
        }

        if (
            !$this->orderHasProducts($order)
            && is_array($orderDetails['items'] ?? null)
            && $orderDetails['items']
        ) {
            try {
                $this->addProducts($order, $orderDetails['items']);
            } catch (\Throwable $exception) {
                self::$logger->warning('iFood could not resume product enrichment for existing local order', [
                    'order_id' => $orderId,
                    'local_order_id' => $order->getId(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($order->getAddressDestination() === null && is_array($orderDetails['delivery'] ?? null)) {
            try {
                $this->addDelivery($order, $orderDetails);
            } catch (\Throwable $exception) {
                self::$logger->warning('iFood could not resume delivery enrichment for existing local order', [
                    'order_id' => $orderId,
                    'local_order_id' => $order->getId(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (
            !$this->orderHasInvoices($order)
            && is_array($orderDetails['payments']['methods'] ?? null)
            && is_array($orderDetails['total'] ?? null)
        ) {
            try {
                $this->addPayments($order, $orderDetails);
            } catch (\Throwable $exception) {
                self::$logger->warning('iFood could not resume payment enrichment for existing local order', [
                    'order_id' => $orderId,
                    'local_order_id' => $order->getId(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($this->shouldAttemptAutoConfirmEntry($order, $eventCode)) {
            $this->autoConfirmOrder($order, $orderId);
        }
    }

    private function buildOrderIntegrationLockKey(string $orderId): string
    {
        return sprintf('ifood-order-integrate:%s', strtolower(trim($orderId)));
    }

    private function acquireOrderIntegrationLock(string $orderId): bool
    {
        try {
            $result = $this->entityManager->getConnection()->fetchOne(
                'SELECT GET_LOCK(:lockKey, 5)',
                ['lockKey' => $this->buildOrderIntegrationLockKey($orderId)]
            );

            $acquired = (int) $result === 1;
            if (!$acquired) {
                self::$logger->warning('iFood could not acquire order integration lock in time', [
                    'order_id' => $orderId,
                    'lock_key' => $this->buildOrderIntegrationLockKey($orderId),
                ]);
            }

            return $acquired;
        } catch (\Throwable $exception) {
            self::$logger->warning('iFood could not acquire order integration lock', [
                'order_id' => $orderId,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function releaseOrderIntegrationLock(string $orderId): void
    {
        try {
            $this->entityManager->getConnection()->executeQuery(
                'SELECT RELEASE_LOCK(:lockKey)',
                ['lockKey' => $this->buildOrderIntegrationLockKey($orderId)]
            );
        } catch (\Throwable $exception) {
            self::$logger->warning('iFood could not release order integration lock', [
                'order_id' => $orderId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function appendOrderEventPayload(Order $order, string $eventCode, array $payload): void
    {
        $entryKey = $eventCode !== '' ? $eventCode : 'UNKNOWN';

        $otherInformations = $this->getDecodedOrderOtherInformations($order);
        if ($otherInformations === []) {
            $otherInformations = [];
        }

        $context = $this->decodeOrderOtherInformationsValue($otherInformations[self::$app] ?? null);
        if ($context === []) {
            $context = [];
        }

        $context[$entryKey] = $payload;
        $context['latest_event_type'] = $entryKey;

        $otherInformations[self::$app] = $context;
        $order->setOtherInformations($otherInformations);
        $this->entityManager->persist($order);
    }

    private function extractWebhookMeta(array $payload): array
    {
        $meta = is_array($payload['__webhook'] ?? null) ? $payload['__webhook'] : [];

        return [
            'event_id' => $this->normalizeString($meta['event_id'] ?? ($payload['id'] ?? null)),
            'event_type' => $this->normalizeString($meta['event_type'] ?? ($payload['fullCode'] ?? ($payload['code'] ?? null))),
            'event_at' => $this->normalizeString($meta['event_at'] ?? ($payload['createdAt'] ?? null)),
            'received_at' => $this->normalizeString($meta['received_at'] ?? date('Y-m-d H:i:s')),
            'shop_id' => $this->normalizeString($meta['shop_id'] ?? ($payload['merchantId'] ?? null)),
            'order_id' => $this->normalizeString($meta['order_id'] ?? ($payload['orderId'] ?? null)),
        ];
    }

    private function extractEventTimestamp(array $payload): string
    {
        $raw = $payload['createdAt']
            ?? ($payload['created_at'] ?? ($payload['__webhook']['event_at'] ?? null));

        if (is_numeric($raw)) {
            $timestamp = (int) $raw;
            if ($timestamp > 9999999999) {
                $timestamp = (int) floor($timestamp / 1000);
            }

            return date('Y-m-d H:i:s', max(0, $timestamp));
        }

        $normalized = $this->normalizeString($raw);
        if ($normalized === '') {
            return date('Y-m-d H:i:s');
        }

        try {
            return (new DateTime($normalized))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return date('Y-m-d H:i:s');
        }
    }

    private function resolveRemoteOrderStateByEventCode(string $eventCode): string
    {
        $normalized = strtoupper(str_replace(['.', '-', ' '], '_', $this->normalizeString($eventCode)));
        if ($normalized === '') {
            return 'unknown';
        }

        return match ($normalized) {
            'PLACED', 'ORDER_CREATED', 'CREATED', 'PENDING', 'ORDER_PENDING' => 'new',
            'CONFIRMED', 'ORDER_CONFIRMED', 'ACCEPTED', 'ORDER_ACCEPTED' => 'confirmed',
            'STARTED', 'PREPARING', 'PREPARATION_STARTED', 'START_PREPARATION', 'ORDER_PREPARATION_STARTED', 'ORDER_IN_PREPARATION' => 'preparing',
            'READY', 'READY_TO_PICKUP', 'ORDER_READY_TO_PICKUP', 'READY_TO_DELIVER', 'RTD' => 'ready',
            'DISPATCHING', 'DISPATCHED', 'ORDER_DISPATCHED', 'ORDER_PICKED_UP', 'ORDER_IN_TRANSIT', 'DELIVERY_STARTED', 'DELIVERY_COLLECTED', 'DCLT', 'DELIVERY_ARRIVED_AT_DESTINATION', 'DAAD' => 'dispatching',
            'DELIVERY_DROP_CODE_REQUESTED' => 'delivery_drop_code_requested',
            'DELIVERY_DROP_CODE_VALIDATING' => 'delivery_drop_code_validating',
            'CONCLUDED', 'ORDER_CONCLUDED', 'ORDER_FINISHED', 'DELIVERY_CONCLUDED' => 'concluded',
            'CANCELLED', 'CANCELED', 'ORDER_CANCELLED', 'ORDER_CANCELED', 'ORDER_CANCELLED_BY_CUSTOMER', 'ORDER_CANCELED_BY_CUSTOMER' => 'cancelled',
            'CANCELLATION_REQUESTED', 'ORDER_CANCELLATION_REQUESTED' => 'cancellation_requested',
            'CANCELLATION_REQUEST_FAILED', 'ORDER_CANCELLATION_REQUEST_FAILED' => 'cancellation_request_failed',
            'HANDSHAKE_DISPUTE', 'HSD' => 'handshake_dispute',
            'HANDSHAKE_SETTLEMENT', 'HSS' => 'handshake_settlement',
            default => strtolower($normalized),
        };
    }

    private function composeAddressDisplayFromPieces(
        ?string $streetName,
        ?string $streetNumber,
        ?string $district,
        ?string $city
    ): string {
        $firstLine = trim(implode(', ', array_filter([
            $this->normalizeString($streetName),
            $this->normalizeString($streetNumber),
        ], fn($value) => $value !== '')));

        $secondLine = trim(implode(' - ', array_filter([
            $this->normalizeString($district),
            $this->normalizeString($city),
        ], fn($value) => $value !== '')));

        return trim(implode(', ', array_filter([$firstLine, $secondLine], fn($value) => $value !== '')));
    }

    private function extractOrderPayloadValue(array $payload, array $paths): string
    {
        foreach ($paths as $path) {
            $cursor = $payload;
            $found = true;

            foreach ($path as $segment) {
                if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                    $found = false;
                    break;
                }

                $cursor = $cursor[$segment];
            }

            if (!$found) {
                continue;
            }

            $value = $this->normalizeString($cursor);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function isMerchantDeliveryContext(string $deliveredBy, string $deliveryMode): bool
    {
        if ($deliveredBy === 'MERCHANT') {
            return true;
        }

        $normalizedDeliveryMode = strtolower($deliveryMode);
        return in_array($normalizedDeliveryMode, ['merchant', 'store', 'self', 'self_delivery', 'own', 'own_fleet'], true);
    }

    private function resolveDefaultSelfDeliveryConfirmationUrl(): string
    {
        return self::SELF_DELIVERY_CONFIRMATION_URL;
    }

    private function resolveOrderSchedulePayload(array $orderPayload): array
    {
        if (is_array($orderPayload['schedule'] ?? null)) {
            return $orderPayload['schedule'];
        }

        return is_array($orderPayload['scheduled'] ?? null) ? $orderPayload['scheduled'] : [];
    }

    private function extractOrderBenefitSnapshot(array $orderPayload): array
    {
        $benefits = is_array($orderPayload['benefits'] ?? null) ? $orderPayload['benefits'] : [];
        if ($benefits === []) {
            return [];
        }

        $discountTotal = 0.0;
        $ifoodSubsidy = 0.0;
        $merchantSubsidy = 0.0;
        $ifoodDeliverySubsidy = 0.0;
        $merchantDeliverySubsidy = 0.0;
        $ifoodNonDeliverySubsidy = 0.0;
        $merchantNonDeliverySubsidy = 0.0;
        $voucherCodes = [];

        foreach ($benefits as $benefit) {
            if (!is_array($benefit)) {
                continue;
            }

            $benefitValue = (float) ($benefit['value'] ?? 0.0);
            $discountTotal += $benefitValue;
            $campaign = is_array($benefit['campaign'] ?? null) ? $benefit['campaign'] : [];
            $code = $this->normalizeString(
                $benefit['code']
                    ?? $benefit['couponCode']
                    ?? $benefit['voucherCode']
                    ?? $campaign['code']
                    ?? $campaign['name']
                    ?? $campaign['id']
                    ?? null
            );
            if ($code !== '') {
                $voucherCodes[$code] = true;
            }

            foreach ((array) ($benefit['sponsorshipValues'] ?? []) as $sponsorship) {
                if (!is_array($sponsorship)) {
                    continue;
                }

                $sponsorName = strtoupper($this->normalizeString($sponsorship['name'] ?? null));
                $value = (float) ($sponsorship['value'] ?? 0.0);
                $isDeliveryTarget = strtoupper($this->normalizeString($benefit['target'] ?? null)) === 'DELIVERY_FEE';
                if ($sponsorName !== '' && $sponsorName !== 'MERCHANT') {
                    $ifoodSubsidy += $value;
                    if ($isDeliveryTarget) {
                        $ifoodDeliverySubsidy += $value;
                    } else {
                        $ifoodNonDeliverySubsidy += $value;
                    }
                    continue;
                }

                if ($sponsorName === 'MERCHANT') {
                    $merchantSubsidy += $value;
                    if ($isDeliveryTarget) {
                        $merchantDeliverySubsidy += $value;
                    } else {
                        $merchantNonDeliverySubsidy += $value;
                    }
                }
            }
        }

        $snapshot = [
            'voucher_code' => implode(', ', array_keys($voucherCodes)),
            'discount_total' => (string) $discountTotal,
            'ifood_subsidy' => (string) $ifoodSubsidy,
            'merchant_subsidy' => (string) $merchantSubsidy,
            'platform_discount_total' => (string) $ifoodSubsidy,
            'store_discount_total' => (string) $merchantSubsidy,
            'platform_delivery_discount_total' => (string) $ifoodDeliverySubsidy,
            'store_delivery_discount_total' => (string) $merchantDeliverySubsidy,
            'platform_non_delivery_discount_total' => (string) $ifoodNonDeliverySubsidy,
            'store_non_delivery_discount_total' => (string) $merchantNonDeliverySubsidy,
        ];

        return array_filter(
            $snapshot,
            static fn($value) => $value !== null && $value !== '' && $value !== '0'
        );
    }

    private function extractAdditionalFeeSnapshot(array $additionalFees): array
    {
        $total = 0.0;
        $merchantTotal = 0.0;
        $merchantServiceFee = 0.0;
        $merchantSmallOrderFee = 0.0;
        $merchantMealTopUpFee = 0.0;

        foreach ($additionalFees as $fee) {
            if (!is_array($fee)) {
                continue;
            }

            $feeValue = round((float) ($fee['value'] ?? 0.0), 2);
            if ($feeValue <= 0) {
                continue;
            }

            $total += $feeValue;
            $merchantShare = $this->resolveIfoodMerchantFeeShare($fee, $feeValue);
            if ($merchantShare <= 0) {
                continue;
            }

            $merchantTotal += $merchantShare;
            $type = strtoupper($this->normalizeString($fee['type'] ?? null));
            if ($type === 'SMALL_ORDER_FEE') {
                $merchantSmallOrderFee += $merchantShare;
                continue;
            }

            if (in_array($type, ['MEAL_TOP_UP_FEE', 'MEAL_VOUCHER_TOP_UP_FEE'], true)) {
                $merchantMealTopUpFee += $merchantShare;
                continue;
            }

            $merchantServiceFee += $merchantShare;
        }

        return [
            'total' => round($total, 2),
            'merchant_total' => round($merchantTotal, 2),
            'merchant_service_fee' => round($merchantServiceFee, 2),
            'merchant_small_order_fee' => round($merchantSmallOrderFee, 2),
            'merchant_meal_top_up_fee' => round($merchantMealTopUpFee, 2),
        ];
    }

    private function resolveIfoodMerchantFeeShare(array $fee, float $feeValue): float
    {
        $liabilities = is_array($fee['liabilities'] ?? null) ? $fee['liabilities'] : [];
        if ($liabilities === []) {
            return 0.0;
        }

        $merchantPercentage = 0.0;
        foreach ($liabilities as $liability) {
            if (!is_array($liability)) {
                continue;
            }

            if (strtoupper($this->normalizeString($liability['name'] ?? null)) !== 'MERCHANT') {
                continue;
            }

            $merchantPercentage += (float) ($liability['percentage'] ?? 0.0);
        }

        return round($feeValue * min(100.0, max(0.0, $merchantPercentage)) / 100, 2);
    }

    private function extractOrderDetailSnapshot(array $orderPayload): array
    {
        if (!$orderPayload) {
            return [];
        }

        $delivery = is_array($orderPayload['delivery'] ?? null) ? $orderPayload['delivery'] : [];
        $deliveryAddress = is_array($delivery['deliveryAddress'] ?? null) ? $delivery['deliveryAddress'] : [];
        $customer = is_array($orderPayload['customer'] ?? null) ? $orderPayload['customer'] : [];
        $phone = is_array($customer['phone'] ?? null) ? $customer['phone'] : [];
        $schedule = $this->resolveOrderSchedulePayload($orderPayload);

        $streetName = $this->normalizeString($deliveryAddress['streetName'] ?? null);
        $streetNumber = $this->normalizeString($deliveryAddress['streetNumber'] ?? null);
        $district = $this->normalizeString($deliveryAddress['neighborhood'] ?? null);
        $city = $this->normalizeString($deliveryAddress['city'] ?? null);
        $state = $this->normalizeString($deliveryAddress['state'] ?? null);
        $postalCode = $this->normalizeString($deliveryAddress['postalCode'] ?? null);
        $reference = $this->normalizeString($deliveryAddress['reference'] ?? null);
        $complement = $this->normalizeString($deliveryAddress['complement'] ?? null);
        $customerDocument = $this->resolveCustomerDocumentNumber($customer);
        $customerDocumentType = $this->resolveCustomerDocumentType($customer, $customerDocument);
        $taxDocumentRequested = $this->resolveTaxDocumentRequested($customer, $customerDocument);

        $addressDisplay = $this->composeAddressDisplayFromPieces(
            $streetName !== '' ? $streetName : null,
            $streetNumber !== '' ? $streetNumber : null,
            $district !== '' ? $district : null,
            $city !== '' ? $city : null
        );

        $remark = $this->extractOrderRemarkFromPayload($orderPayload);

        $payments = is_array($orderPayload['payments'] ?? null) ? $orderPayload['payments'] : [];
        $methods = is_array($payments['methods'] ?? null) ? $payments['methods'] : [];
        $firstMethod = is_array($methods[0] ?? null) ? $methods[0] : [];
        $methodCode = strtoupper($this->normalizeString($firstMethod['method'] ?? null));
        $methodType = strtoupper($this->normalizeString($firstMethod['type'] ?? null));
        $brand = strtoupper($this->normalizeString($firstMethod['card']['brand'] ?? null));
        $amountPaid = (float) ($payments['prepaid'] ?? 0.0);
        $amountPending = (float) ($payments['pending'] ?? 0.0);
        $changeFor = (float) ($firstMethod['cash']['changeFor'] ?? 0.0);
        $selectedPaymentLabel = trim($methodCode . ($brand !== '' ? " ({$brand})" : ''));
        $paymentLiability = strtoupper($this->normalizeString($firstMethod['liability'] ?? null));
        $paymentWalletName = $this->normalizeString($firstMethod['wallet']['name'] ?? null);
        $orderType = strtoupper($this->normalizeString($orderPayload['orderType'] ?? null));
        $orderTiming = strtoupper($this->normalizeString($orderPayload['orderTiming'] ?? null));
        $scheduledStart = $this->normalizeString(
            $schedule['deliveryDateTimeStart']
                ?? $schedule['scheduledDateTimeStart']
                ?? null
        );
        $scheduledEnd = $this->normalizeString(
            $schedule['deliveryDateTimeEnd']
                ?? $schedule['scheduledDateTimeEnd']
                ?? null
        );
        $takeout = is_array($orderPayload['takeout'] ?? null) ? $orderPayload['takeout'] : [];
        $dineIn = is_array($orderPayload['dineIn'] ?? null) ? $orderPayload['dineIn'] : [];
        $deliveryDateTime = $this->normalizeString(
            $delivery['deliveryDateTime']
                ?? $delivery['estimatedDeliveryDate']
                ?? null
        );
        $preparationStart = $this->normalizeString(
            $orderPayload['preparationStartDateTime']
                ?? $schedule['preparationStartDateTime']
                ?? null
        );
        $takeoutMode = strtoupper($this->normalizeString($takeout['mode'] ?? null));
        $takeoutDateTime = $this->normalizeString(
            $takeout['takeoutDateTime']
                ?? $takeout['pickupDateTime']
                ?? null
        );
        $dineInDateTime = $this->normalizeString(
            $dineIn['deliveryDateTime']
                ?? $dineIn['dineInDateTime']
                ?? null
        );

        $deliveredBy = strtoupper($this->normalizeString($delivery['deliveredBy'] ?? null));
        $deliveryMode = $this->normalizeString($delivery['mode'] ?? ($delivery['deliveryMode'] ?? null));
        $displayId = $this->normalizeString($orderPayload['displayId'] ?? null);
        $pickupCode = $this->extractOrderPayloadValue($orderPayload, [
            ['delivery', 'pickupCode'],
            ['delivery', 'pickup_code'],
        ]);
        $locator = $this->extractOrderPayloadValue($orderPayload, [
            ['delivery', 'locator'],
            ['delivery', 'localizer'],
            ['delivery', 'deliveryAddress', 'locator'],
            ['delivery', 'deliveryAddress', 'localizer'],
            ['customer', 'phone', 'localizer'],
            ['customer', 'phone', 'localizerId'],
            ['phone', 'localizer'],
            ['phone', 'localizerId'],
        ]);
        $handoverPageUrl = $this->extractOrderPayloadValue($orderPayload, [
            ['delivery', 'handoverPageUrl'],
            ['delivery', 'handover_page_url'],
            ['delivery', 'confirmationUrl'],
            ['delivery', 'confirmation_url'],
            ['delivery', 'confirmation', 'url'],
            ['handoverPageUrl'],
            ['handover_page_url'],
        ]);
        $handoverConfirmationUrl = $this->extractOrderPayloadValue($orderPayload, [
            ['delivery', 'handoverConfirmationUrl'],
            ['delivery', 'handover_confirmation_url'],
            ['delivery', 'deliveryConfirmationUrl'],
            ['delivery', 'delivery_confirmation_url'],
            ['delivery', 'confirmationUrl'],
            ['delivery', 'confirmation_url'],
            ['delivery', 'confirmation', 'url'],
            ['handoverConfirmationUrl'],
            ['handover_confirmation_url'],
        ]);
        $pickupAreaCode = $this->extractOrderPayloadValue($orderPayload, [
            ['pickup', 'area', 'code'],
            ['pickup', 'areaCode'],
            ['pickupArea', 'code'],
            ['takeout', 'pickupArea', 'code'],
            ['takeout', 'pickupAreaCode'],
        ]);
        $pickupAreaType = $this->extractOrderPayloadValue($orderPayload, [
            ['pickup', 'area', 'type'],
            ['pickup', 'areaType'],
            ['pickupArea', 'type'],
            ['takeout', 'pickupArea', 'type'],
            ['takeout', 'pickupAreaType'],
        ]);

        if ($handoverConfirmationUrl === '' && $handoverPageUrl !== '') {
            $handoverConfirmationUrl = $handoverPageUrl;
        }

        if ($handoverPageUrl === '' && $handoverConfirmationUrl !== '') {
            $handoverPageUrl = $handoverConfirmationUrl;
        }

        if (
            $handoverConfirmationUrl === ''
            && $this->isMerchantDeliveryContext($deliveredBy, $deliveryMode)
        ) {
            $handoverConfirmationUrl = $this->resolveDefaultSelfDeliveryConfirmationUrl();
            $handoverPageUrl = $handoverPageUrl !== '' ? $handoverPageUrl : $handoverConfirmationUrl;
        }

        $snapshot = [
            'code' => $displayId,
            'order_type' => $orderType,
            'order_timing' => $orderTiming,
            'delivered_by' => $deliveredBy,
            'delivery_mode' => $deliveryMode,
            'takeout_mode' => $takeoutMode,
            'takeout_date_time' => $takeoutDateTime,
            'dine_in_date_time' => $dineInDateTime,
            'pickup_code' => $pickupCode,
            'pickup_area_code' => $pickupAreaCode,
            'pickup_area_type' => $pickupAreaType,
            'handover_code' => $pickupCode,
            'locator' => $locator,
            'handover_page_url' => $handoverPageUrl,
            'handover_confirmation_url' => $handoverConfirmationUrl,
            'virtual_phone' => $this->normalizeString($phone['localizer'] ?? null),
            'customer_name' => $this->normalizeString($customer['name'] ?? null),
            'customer_phone' => $this->normalizeString($phone['number'] ?? null),
            'customer_document' => $customerDocument,
            'customer_document_type' => $customerDocumentType,
            'tax_document_requested' => $taxDocumentRequested,
            'address_display' => $this->normalizeString($deliveryAddress['formattedAddress'] ?? null),
            'address_poi_address' => $this->normalizeString($deliveryAddress['formattedAddress'] ?? null),
            'address_street_name' => $streetName,
            'address_street_number' => $streetNumber,
            'address_district' => $district,
            'address_city' => $city,
            'address_state' => $state,
            'address_postal_code' => $postalCode,
            'address_reference' => $reference,
            'address_complement' => $complement,
            'remark' => $remark,
            'pay_type' => $methodType !== '' ? strtolower($methodType) : '',
            'pay_method' => $methodCode !== '' ? strtolower($methodCode) : '',
            'pay_channel' => $brand !== '' ? $brand : $methodCode,
            'payment_liability' => $paymentLiability,
            'payment_wallet_name' => $paymentWalletName,
            'selected_payment_label' => $selectedPaymentLabel,
            'scheduled_start' => $scheduledStart,
            'scheduled_end' => $scheduledEnd,
            'delivery_date_time' => $deliveryDateTime,
            'preparation_start' => $preparationStart,
            'is_scheduled' => $orderTiming === 'SCHEDULED',
            'amount_paid' => (string) $amountPaid,
            'amount_pending' => (string) $amountPending,
            'customer_need_paying_money' => (string) $amountPending,
            'collect_on_delivery_amount' => (string) ($amountPending > 0.0 ? $amountPending : 0.0),
            'change_for' => (string) $changeFor,
            'change_amount' => (string) max(0.0, $changeFor - $amountPending),
            'needs_change' => $changeFor > 0.009,
        ];

        if (($snapshot['address_display'] ?? '') === '' && $addressDisplay !== '') {
            $snapshot['address_display'] = $addressDisplay;
        }

        return array_filter(
            array_merge($snapshot, $this->extractOrderBenefitSnapshot($orderPayload)),
            static fn($value) => $value !== null && $value !== ''
        );
    }

    private function extractOrderRemarkFromPayload(array $orderPayload): string
    {
        $delivery = is_array($orderPayload['delivery'] ?? null) ? $orderPayload['delivery'] : [];
        $additionalInfo = $orderPayload['additionalInfo'] ?? null;
        $remark = '';

        if (is_array($additionalInfo)) {
            $remark = $this->normalizeMarketplaceFreeText(
                $additionalInfo['notes'] ?? $additionalInfo['observation'] ?? null
            );
        } else {
            $remark = $this->normalizeMarketplaceFreeText($additionalInfo);
        }

        if ($remark === '') {
            $remark = $this->normalizeMarketplaceFreeText($delivery['observations'] ?? null);
        }

        if ($remark === '') {
            $remark = $this->normalizeMarketplaceFreeText($orderPayload['orderComment'] ?? null);
        }

        return $remark;
    }

    private function extractItemRemark(array $item): string
    {
        return $this->normalizeMarketplaceFreeText(
            $item['observations']
                ?? $item['observation']
                ?? $item['notes']
                ?? $item['note']
                ?? $item['comment']
                ?? null
        );
    }

    private function extractHandshakeEventSnapshot(array $payload): array
    {
        $eventCode = $this->resolveEventCode($payload);
        if (!in_array($eventCode, ['HANDSHAKE_DISPUTE', 'HSD', 'HANDSHAKE_SETTLEMENT', 'HSS'], true)) {
            return [];
        }

        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $dispute = is_array($payload['dispute'] ?? null) ? $payload['dispute'] : [];
        $settlement = is_array($payload['settlement'] ?? null) ? $payload['settlement'] : [];
        $source = array_merge($metadata, $dispute, $settlement);
        $acceptReasons = [];
        foreach ((array) ($source['acceptCancellationReasons'] ?? []) as $reason) {
            $normalizedReason = strtoupper($this->normalizeString($reason));
            if ($normalizedReason !== '') {
                $acceptReasons[$normalizedReason] = true;
            }
        }
        $alternatives = is_array($source['alternatives'] ?? null) ? $source['alternatives'] : [];
        $alternative = is_array($alternatives[0] ?? null) ? $alternatives[0] : [];
        $alternativeMetadata = is_array($alternative['metadata'] ?? null) ? $alternative['metadata'] : [];
        $alternativeAmount = is_array($alternativeMetadata['maxAmount'] ?? null)
            ? $alternativeMetadata['maxAmount']
            : (is_array($alternativeMetadata['amount'] ?? null) ? $alternativeMetadata['amount'] : []);
        $alternativeTimes = is_array($alternativeMetadata['allowedsAdditionalTimeInMinutes'] ?? null)
            ? $alternativeMetadata['allowedsAdditionalTimeInMinutes']
            : (is_array($alternativeMetadata['allowedAdditionalTimeInMinutes'] ?? null) ? $alternativeMetadata['allowedAdditionalTimeInMinutes'] : []);
        $alternativeReasons = is_array($alternativeMetadata['allowedsAdditionalTimeReasons'] ?? null)
            ? $alternativeMetadata['allowedsAdditionalTimeReasons']
            : (is_array($alternativeMetadata['allowedAdditionalTimeReasons'] ?? null) ? $alternativeMetadata['allowedAdditionalTimeReasons'] : []);
        $evidences = is_array($source['evidences'] ?? null) ? $source['evidences'] : [];
        $evidence = is_array($evidences[0] ?? null) ? $evidences[0] : [];
        $selectedAlternative = is_array($source['selectedDisputeAlternative'] ?? null)
            ? $source['selectedDisputeAlternative']
            : (is_array($source['selected_dispute_alternative'] ?? null) ? $source['selected_dispute_alternative'] : []);

        $snapshot = [
            'handshake_event_type' => $eventCode,
            'handshake_dispute_id' => $this->normalizeString(
                $source['disputeId']
                    ?? $source['dispute_id']
                    ?? $source['id']
                    ?? $payload['id']
                    ?? null
            ),
            'handshake_created_at' => $this->normalizeString(
                $source['createdAt']
                    ?? $source['created_at']
                    ?? null
            ),
            'handshake_action' => $this->normalizeString($source['action'] ?? null),
            'handshake_type' => $this->normalizeString(
                $source['handshakeType']
                    ?? $source['handshake_type']
                    ?? $source['type']
                    ?? null
            ),
            'handshake_group' => $this->normalizeString(
                $source['handshakeGroup']
                    ?? $source['handshake_group']
                    ?? $source['group']
                    ?? null
            ),
            'handshake_message' => $this->normalizeString(
                $source['message']
                    ?? $source['description']
                    ?? $source['reason']
                    ?? null
            ),
            'handshake_expires_at' => $this->normalizeString(
                $source['expiresAt']
                    ?? $source['expires_at']
                    ?? null
            ),
            'handshake_timeout_action' => $this->normalizeString(
                $source['timeoutAction']
                    ?? $source['timeout_action']
                    ?? null
            ),
            'handshake_accept_reasons' => implode(',', array_keys($acceptReasons)),
            'handshake_alternatives_json' => $this->encodeCompactJson($alternatives),
            'handshake_alternative_id' => $this->normalizeString($alternative['id'] ?? null),
            'handshake_alternative_type' => strtoupper($this->normalizeString($alternative['type'] ?? null)),
            'handshake_alternative_amount_value' => $this->normalizeString($alternativeAmount['value'] ?? null),
            'handshake_alternative_amount_currency' => $this->normalizeString($alternativeAmount['currency'] ?? null),
            'handshake_alternative_time_minutes' => $this->normalizeString($alternativeTimes[0] ?? null),
            'handshake_alternative_reason' => strtoupper($this->normalizeString($alternativeReasons[0] ?? null)),
            'handshake_evidences_json' => $this->encodeCompactJson($evidences),
            'handshake_evidence_url' => $this->normalizeString($evidence['url'] ?? null),
            'handshake_evidence_content_type' => $this->normalizeString($evidence['contentType'] ?? ($evidence['content_type'] ?? null)),
            'handshake_selected_alternative_json' => $this->encodeCompactJson($selectedAlternative),
            'handshake_settlement_status' => $this->normalizeString(
                $source['settlementStatus']
                    ?? $source['status']
                    ?? null
            ),
            'handshake_settlement_reason' => $this->normalizeString(
                $source['settlementReason']
                    ?? $source['reason']
                    ?? null
            ),
        ];

        return array_filter($snapshot, static fn($value) => $value !== null && $value !== '');
    }

    private function encodeCompactJson(array $payload): string
    {
        if ($payload === []) {
            return '';
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '';
    }

    private function persistIncomingEventState(Order $order, Integration $integration, array $payload): void
    {
        $eventCode = $this->resolveEventCode($payload);
        $meta = $this->extractWebhookMeta($payload);
        $eventTimestamp = $this->extractEventTimestamp($payload);
        $orderId = $this->normalizeString($payload['orderId'] ?? ($meta['order_id'] ?? null));
        $merchantId = $this->normalizeString($payload['merchantId'] ?? ($meta['shop_id'] ?? null));
        $remoteOrderState = $this->resolveRemoteOrderStateByEventCode($eventCode);

        $statePayload = [
            'id' => $orderId,
            'merchant_id' => $merchantId,
            'last_event_type' => $eventCode,
            'last_event_at' => $eventTimestamp,
            'webhook_event_id' => $meta['event_id'],
            'webhook_event_type' => $meta['event_type'],
            'webhook_event_at' => $meta['event_at'],
            'webhook_received_at' => $meta['received_at'],
            'webhook_processed_at' => date('Y-m-d H:i:s'),
            'last_integration_id' => (string) $integration->getId(),
        ];

        if (!in_array($remoteOrderState, ['handshake_dispute', 'handshake_settlement'], true)) {
            $statePayload['remote_order_state'] = $remoteOrderState;
        }

        $orderPayload = is_array($payload['order'] ?? null) ? $payload['order'] : [];
        if ($orderPayload) {
            $statePayload = array_merge($statePayload, $this->extractOrderDetailSnapshot($orderPayload));
            $this->syncOrderComments($order, $statePayload['remark'] ?? null);
        }

        $statePayload = array_merge($statePayload, $this->extractHandshakeEventSnapshot($payload));

        $this->persistOrderIntegrationState($order, $statePayload);
    }

    private function applyLocalCanceledStatus(Order $order): void
    {
        $this->applyLocalStatus($order, 'canceled', 'canceled');
    }

    private function applyLocalClosedStatus(Order $order): void
    {
        $this->applyLocalStatus($order, 'closed', 'closed');
    }

    private function resolveOperationalStatusRank(string $realStatus, string $statusName): ?int
    {
        $normalizedRealStatus = strtolower(trim($realStatus));
        $normalizedStatusName = strtolower(trim($statusName));

        return match ($normalizedRealStatus . ':' . $normalizedStatusName) {
            'open:open' => 10,
            'open:preparing' => 20,
            'pending:ready' => 30,
            'pending:way' => 40,
            'closed:closed' => 50,
            'canceled:canceled', 'cancelled:cancelled', 'canceled:cancelled', 'cancelled:canceled' => 60,
            default => null,
        };
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

        $currentRank = $this->resolveOperationalStatusRank($currentRealStatus, $currentStatusName);
        $newRank = $this->resolveOperationalStatusRank($normalizedRealStatus, $normalizedStatusName);
        if ($currentRank !== null && $newRank !== null && $newRank < $currentRank) {
            return;
        }

        $status = $this->statusService->discoveryStatus($normalizedRealStatus, $normalizedStatusName, 'order');
        $order->setStatus($status);
        $this->entityManager->persist($order);
    }

    private function resolveOperationalStatusFromRemoteState(?string $remoteState): ?array
    {
        $normalizedRemoteState = strtolower(trim((string) $remoteState));

        return match ($normalizedRemoteState) {
            'new' => ['realStatus' => 'open', 'status' => 'open'],
            'confirmed', 'preparing' => ['realStatus' => 'open', 'status' => 'preparing'],
            'ready' => ['realStatus' => 'pending', 'status' => 'ready'],
            'dispatching' => ['realStatus' => 'pending', 'status' => 'way'],
            'concluded' => ['realStatus' => 'closed', 'status' => 'closed'],
            'cancelled', 'canceled' => ['realStatus' => 'canceled', 'status' => 'canceled'],
            default => null,
        };
    }

    private function applyOperationalStatusForRemoteState(Order $order, ?string $remoteState): void
    {
        $statusMapping = $this->resolveOperationalStatusFromRemoteState($remoteState);
        if (!is_array($statusMapping)) {
            return;
        }

        $this->applyLocalStatus(
            $order,
            (string) ($statusMapping['realStatus'] ?? ''),
            (string) ($statusMapping['status'] ?? '')
        );
    }

    private function upsertIfoodExtraDataValue(
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

    private function persistOrderIntegrationState(Order $order, array $fields): void
    {
        $normalizedFields = [];
        foreach ($fields as $fieldName => $value) {
            $normalizedFieldName = trim((string) $fieldName);
            if ($normalizedFieldName === '') {
                continue;
            }

            $normalizedFields[$normalizedFieldName] = $value;
        }

        if ($normalizedFields === []) {
            return;
        }

        $this->mergeEntityOtherInformations($order, self::APP_CONTEXT, $normalizedFields);
    }

    private function getIfoodExtraDataValue(string $entityName, int $entityId, string $fieldName = 'code'): ?string
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
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return [];
    }

    private function getDecodedOrderOtherInformations(Order $order): array
    {
        try {
            $otherInformations = $this->decodeOrderOtherInformationsValue($order->getOtherInformations(true));

            return $otherInformations !== []
                ? $otherInformations
                : $this->decodeOrderOtherInformationsValue($order->getOtherInformations());
        } catch (\Throwable) {
            return [];
        }
    }

    public function updateItemPrice(People $provider, string $itemId, float $price): array
    {
        $this->init();

        $token = $this->getAccessToken();
        if (!$token) {
            return ['errno' => 10001, 'errmsg' => 'Token iFood indisponivel.'];
        }

        $state      = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($state['merchant_id'] ?? null);
        if ($merchantId === '') {
            return ['errno' => 10002, 'errmsg' => 'Loja iFood nao conectada.'];
        }

        $normalizedItemId = $this->normalizeString($itemId);
        if ($normalizedItemId === '') {
            return ['errno' => 10003, 'errmsg' => 'item_id nao informado.'];
        }

        $roundedPrice = round($price, 2);
        if ($roundedPrice <= 0) {
            return ['errno' => 10004, 'errmsg' => 'Preco deve ser maior que zero.'];
        }

        try {
            $response = $this->ifoodClient->request(
                'PATCH',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/items/price',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'itemId' => $normalizedItemId,
                        'price'  => [
                            'value'         => $roundedPrice,
                            'originalValue' => $roundedPrice,
                        ],
                    ],
                ]
            );

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return ['errno' => 0, 'errmsg' => '', 'data' => ['item_id' => $normalizedItemId, 'price' => $roundedPrice]];
            }

            $body = substr($response->getContent(false), 0, 500);
            self::$logger->error('iFood updateItemPrice falhou', [
                'merchant_id' => $merchantId,
                'item_id'     => $normalizedItemId,
                'status'      => $status,
                'body'        => $body,
            ]);

            return ['errno' => $status, 'errmsg' => 'Erro ao atualizar preco no iFood. Status: ' . $status];
        } catch (\Throwable $e) {
            self::$logger->error('iFood updateItemPrice excecao', [
                'merchant_id' => $merchantId,
                'item_id'     => $normalizedItemId,
                'error'       => $e->getMessage(),
            ]);

            return ['errno' => 1, 'errmsg' => 'Falha ao atualizar preco: ' . $e->getMessage()];
        }
    }

    // RETORNA HORARIOS DE FUNCIONAMENTO DA LOJA NO IFOOD
    // GET /merchant/v1.0/merchants/{merchantId}/opening-hours
    public function getOpeningHours(People $provider): array
    {
        $this->init();

        $token = $this->getAccessToken();
        if (!$token) {
            return ['errno' => 10001, 'errmsg' => 'Token iFood indisponivel.'];
        }

        $state      = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($state['merchant_id'] ?? null);
        if ($merchantId === '') {
            return ['errno' => 10002, 'errmsg' => 'Loja iFood nao conectada.'];
        }

        try {
            $response = $this->ifoodClient->request(
                'GET',
                self::API_BASE_URL . '/merchant/v1.0/merchants/' . rawurlencode($merchantId) . '/opening-hours',
                ['headers' => ['Authorization' => 'Bearer ' . $token]]
            );

            $httpStatus = $response->getStatusCode();
            if ($httpStatus >= 200 && $httpStatus < 300) {
                $decoded = json_decode($response->getContent(false), true);
                return ['errno' => 0, 'errmsg' => '', 'data' => is_array($decoded) ? $decoded : []];
            }

            $body = substr($response->getContent(false), 0, 500);
            self::$logger->error('iFood getOpeningHours falhou', [
                'merchant_id' => $merchantId,
                'status'      => $httpStatus,
                'body'        => $body,
            ]);

            return ['errno' => $httpStatus, 'errmsg' => 'Erro ao buscar horarios no iFood. Status: ' . $httpStatus];
        } catch (\Throwable $e) {
            self::$logger->error('iFood getOpeningHours excecao', [
                'merchant_id' => $merchantId,
                'error'       => $e->getMessage(),
            ]);

            return ['errno' => 1, 'errmsg' => 'Falha ao buscar horarios: ' . $e->getMessage()];
        }
    }

    // ATUALIZA HORARIOS DE FUNCIONAMENTO DA LOJA NO IFOOD
    // PUT /merchant/v1.0/merchants/{merchantId}/opening-hours
    public function updateOpeningHours(People $provider, array $shifts): array
    {
        $this->init();

        $token = $this->getAccessToken();
        if (!$token) {
            return ['errno' => 10001, 'errmsg' => 'Token iFood indisponivel.'];
        }

        $state      = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($state['merchant_id'] ?? null);
        if ($merchantId === '') {
            return ['errno' => 10002, 'errmsg' => 'Loja iFood nao conectada.'];
        }

        // Normaliza shifts para o formato esperado pelo iFood:
        // [{dayOfWeek, start:"HH:MM:SS", duration}] — flat, sem agrupamento
        $flatShifts = [];
        foreach ($shifts as $shift) {
            $dayOfWeek = $this->normalizeString($shift['dayOfWeek'] ?? null);
            $start     = $this->normalizeString($shift['start']     ?? null);
            $duration  = (int) ($shift['duration'] ?? 0);
            if ($dayOfWeek === '' || $start === '' || $duration <= 0) continue;
            // Garante formato HH:MM:SS
            if (substr_count($start, ':') === 1) $start .= ':00';
            $flatShifts[] = ['dayOfWeek' => $dayOfWeek, 'start' => $start, 'duration' => $duration];
        }

        $payload = ['storeId' => $merchantId, 'shifts' => $flatShifts];

        try {
            $response = $this->ifoodClient->request(
                'PUT',
                self::API_BASE_URL . '/merchant/v1.0/merchants/' . rawurlencode($merchantId) . '/opening-hours',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => $payload,
                ]
            );

            $httpStatus = $response->getStatusCode();
            if ($httpStatus >= 200 && $httpStatus < 300) {
                return ['errno' => 0, 'errmsg' => '', 'data' => ['updated' => true]];
            }

            $body = substr($response->getContent(false), 0, 500);
            self::$logger->error('iFood updateOpeningHours falhou', [
                'merchant_id' => $merchantId,
                'status'      => $httpStatus,
                'body'        => $body,
            ]);

            return ['errno' => $httpStatus, 'errmsg' => 'Erro ao atualizar horarios no iFood. Status: ' . $httpStatus];
        } catch (\Throwable $e) {
            self::$logger->error('iFood updateOpeningHours excecao', [
                'merchant_id' => $merchantId,
                'error'       => $e->getMessage(),
            ]);

            return ['errno' => 1, 'errmsg' => 'Falha ao atualizar horarios: ' . $e->getMessage()];
        }
    }

    // ATUALIZA PRECO DE COMPLEMENTO NO CATALOGO IFOOD
    // PATCH /catalog/v2.0/merchants/{merchantId}/options/price
    public function updateOptionPrice(People $provider, string $optionId, float $price): array
    {
        $this->init();

        $token = $this->getAccessToken();
        if (!$token) {
            return ['errno' => 10001, 'errmsg' => 'Token iFood indisponivel.'];
        }

        $state      = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($state['merchant_id'] ?? null);
        if ($merchantId === '') {
            return ['errno' => 10002, 'errmsg' => 'Loja iFood nao conectada.'];
        }

        $normalizedOptionId = $this->normalizeString($optionId);
        if ($normalizedOptionId === '') {
            return ['errno' => 10003, 'errmsg' => 'option_id nao informado.'];
        }

        $roundedPrice = round($price, 2);
        if ($roundedPrice <= 0) {
            return ['errno' => 10004, 'errmsg' => 'Preco deve ser maior que zero.'];
        }

        try {
            $response = $this->ifoodClient->request(
                'PATCH',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/options/price',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'optionId' => $normalizedOptionId,
                        'price'    => [
                            'value'         => $roundedPrice,
                            'originalValue' => $roundedPrice,
                        ],
                    ],
                ]
            );

            $httpStatus = $response->getStatusCode();
            if ($httpStatus >= 200 && $httpStatus < 300) {
                return ['errno' => 0, 'errmsg' => '', 'data' => ['option_id' => $normalizedOptionId, 'price' => $roundedPrice]];
            }

            $body = substr($response->getContent(false), 0, 500);
            self::$logger->error('iFood updateOptionPrice falhou', [
                'merchant_id' => $merchantId,
                'option_id'   => $normalizedOptionId,
                'status'      => $httpStatus,
                'body'        => $body,
            ]);

            return ['errno' => $httpStatus, 'errmsg' => 'Erro ao atualizar preco do complemento no iFood. Status: ' . $httpStatus];
        } catch (\Throwable $e) {
            self::$logger->error('iFood updateOptionPrice excecao', [
                'merchant_id' => $merchantId,
                'option_id'   => $normalizedOptionId,
                'error'       => $e->getMessage(),
            ]);

            return ['errno' => 1, 'errmsg' => 'Falha ao atualizar preco do complemento: ' . $e->getMessage()];
        }
    }

    // ATUALIZA STATUS DO ITEM NO CATALOGO IFOOD
    // PATCH /catalog/v2.0/merchants/{merchantId}/items/status
    public function updateItemStatus(People $provider, string $itemId, string $status): array
    {
        $this->init();

        $token = $this->getAccessToken();
        if (!$token) {
            return ['errno' => 10001, 'errmsg' => 'Token iFood indisponivel.'];
        }

        $state      = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($state['merchant_id'] ?? null);
        if ($merchantId === '') {
            return ['errno' => 10002, 'errmsg' => 'Loja iFood nao conectada.'];
        }

        $normalizedItemId = $this->normalizeString($itemId);
        if ($normalizedItemId === '') {
            return ['errno' => 10003, 'errmsg' => 'item_id nao informado.'];
        }

        $allowedStatuses = ['AVAILABLE', 'UNAVAILABLE'];
        $normalizedStatus = strtoupper($this->normalizeString($status));
        if (!in_array($normalizedStatus, $allowedStatuses, true)) {
            return ['errno' => 10005, 'errmsg' => 'Status invalido. Use AVAILABLE ou UNAVAILABLE.'];
        }

        try {
            $response = $this->ifoodClient->request(
                'PATCH',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/items/status',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'itemId' => $normalizedItemId,
                        'status' => $normalizedStatus,
                    ],
                ]
            );

            $httpStatus = $response->getStatusCode();
            if ($httpStatus >= 200 && $httpStatus < 300) {
                return ['errno' => 0, 'errmsg' => '', 'data' => ['item_id' => $normalizedItemId, 'status' => $normalizedStatus]];
            }

            $body = substr($response->getContent(false), 0, 500);
            self::$logger->error('iFood updateItemStatus falhou', [
                'merchant_id' => $merchantId,
                'item_id'     => $normalizedItemId,
                'status'      => $httpStatus,
                'body'        => $body,
            ]);

            return ['errno' => $httpStatus, 'errmsg' => 'Erro ao atualizar status no iFood. Status HTTP: ' . $httpStatus];
        } catch (\Throwable $e) {
            self::$logger->error('iFood updateItemStatus excecao', [
                'merchant_id' => $merchantId,
                'item_id'     => $normalizedItemId,
                'error'       => $e->getMessage(),
            ]);

            return ['errno' => 1, 'errmsg' => 'Falha ao atualizar status: ' . $e->getMessage()];
        }
    }

    // ATUALIZA STATUS DE COMPLEMENTO NO CATALOGO IFOOD
    // PATCH /catalog/v2.0/merchants/{merchantId}/options/status
    public function updateOptionStatus(People $provider, string $optionId, string $status): array
    {
        $this->init();

        $token = $this->getAccessToken();
        if (!$token) {
            return ['errno' => 10001, 'errmsg' => 'Token iFood indisponivel.'];
        }

        $state      = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($state['merchant_id'] ?? null);
        if ($merchantId === '') {
            return ['errno' => 10002, 'errmsg' => 'Loja iFood nao conectada.'];
        }

        $normalizedOptionId = $this->normalizeString($optionId);
        if ($normalizedOptionId === '') {
            return ['errno' => 10003, 'errmsg' => 'option_id nao informado.'];
        }

        $allowedStatuses  = ['AVAILABLE', 'UNAVAILABLE'];
        $normalizedStatus = strtoupper($this->normalizeString($status));
        if (!in_array($normalizedStatus, $allowedStatuses, true)) {
            return ['errno' => 10005, 'errmsg' => 'Status invalido. Use AVAILABLE ou UNAVAILABLE.'];
        }

        try {
            $response = $this->ifoodClient->request(
                'PATCH',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/options/status',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type'  => 'application/json',
                    ],
                    'json' => [
                        'optionId' => $normalizedOptionId,
                        'status'   => $normalizedStatus,
                    ],
                ]
            );

            $httpStatus = $response->getStatusCode();
            if ($httpStatus >= 200 && $httpStatus < 300) {
                return ['errno' => 0, 'errmsg' => '', 'data' => ['option_id' => $normalizedOptionId, 'status' => $normalizedStatus]];
            }

            $body = substr($response->getContent(false), 0, 500);
            self::$logger->error('iFood updateOptionStatus falhou', [
                'merchant_id' => $merchantId,
                'option_id'   => $normalizedOptionId,
                'status'      => $httpStatus,
                'body'        => $body,
            ]);

            return ['errno' => $httpStatus, 'errmsg' => 'Erro ao atualizar status do complemento no iFood. Status HTTP: ' . $httpStatus];
        } catch (\Throwable $e) {
            self::$logger->error('iFood updateOptionStatus excecao', [
                'merchant_id' => $merchantId,
                'option_id'   => $normalizedOptionId,
                'error'       => $e->getMessage(),
            ]);

            return ['errno' => 1, 'errmsg' => 'Falha ao atualizar status do complemento: ' . $e->getMessage()];
        }
    }
}
