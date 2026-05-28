<?php

namespace ControleOnline\Service\Marketplace;

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
use ControleOnline\Service\Food99Service;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationHandlerInterface;
use ControleOnline\Service\Marketplace\AbstractMarketplaceService;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationStateProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceLogisticsQuoteProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceOrderSnapshotProviderInterface;
use ControleOnline\Event\EntityChangedEvent;
use DateTime;
use DateTimeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

class Food99OrderOperationsService extends AbstractMarketplaceService
{
    private const APP_CONTEXT = Order::APP_FOOD99;

    protected function getMarketplaceApp(): string
    {
        return self::APP_CONTEXT;
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
                self::$logger?->warning('Food99 could not acquire order integration lock in time', [
                    'order_id' => $orderId,
                    'lock_key' => $this->buildOrderIntegrationLockKey($orderId),
                ]);
            }

            return $acquired === 1;
        } catch (\Throwable $e) {
            self::$logger?->warning('Food99 could not acquire order integration lock', [
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
            self::$logger?->warning('Food99 could not release order integration lock', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extractIncomingOrderIdentifiers(array $json): array
    {
        $identifiers = $this->callFood99ServiceMethod(__FUNCTION__, [$json]);

        return is_array($identifiers) ? $identifiers : [
            'order_id' => '',
            'order_index' => '',
            'order_code' => '',
        ];
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

            $storedOrder = $this->callFood99ServiceMethod('findFood99OrderByStoredIntegrationState', [
                $orderId,
                $orderCode,
            ]);
            if ($storedOrder instanceof Order) {
                return $storedOrder;
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

    private function callFood99ServiceMethod(string $method, array $arguments = []): mixed
    {
        $service = $this->resolveMarketplaceServiceInstance(Food99Service::class);
        if (!is_object($service)) {
            return null;
        }

        return $this->invokeMarketplaceServiceMethod($service, $method, $arguments);
    }

    private function getStoredOrderIntegrationState(Order $order): array
    {
        $state = $this->callFood99ServiceMethod(__FUNCTION__, [$order]);

        return is_array($state) ? $state : [];
    }

    private function normalizeCancelReasonId(mixed $value): ?int
    {
        $reasonId = $this->callFood99ServiceMethod(__FUNCTION__, [$value]);

        return is_int($reasonId) ? $reasonId : null;
    }

    private function callFood99PeopleServiceMethod(string $method, array $arguments = []): mixed
    {
        $service = $this->resolveMarketplaceServiceInstance(Food99PeopleOperationsService::class);
        if (!is_object($service)) {
            return null;
        }

        return $this->invokeMarketplaceServiceMethod($service, $method, $arguments);
    }

    private function callFood99StoreServiceMethod(string $method, array $arguments = []): mixed
    {
        $service = $this->resolveMarketplaceServiceInstance(Food99StoreOperationsService::class);
        if (!is_object($service)) {
            return null;
        }

        return $this->invokeMarketplaceServiceMethod($service, $method, $arguments);
    }

    private function callFood99FinancialServiceMethod(string $method, array $arguments = []): mixed
    {
        $service = $this->resolveMarketplaceServiceInstance(Food99FinancialOperationsService::class);
        if (!is_object($service)) {
            return null;
        }

        return $this->invokeMarketplaceServiceMethod($service, $method, $arguments);
    }

    private function searchPayloadValueByKeys(mixed $payload, array $keys): ?string
    {
        return $this->callFood99PeopleServiceMethod(__FUNCTION__, [$payload, $keys]);
    }

    private function extractFood99PayloadValueFromNestedSections(array $json, array $directKeys, array $nestedKeys): ?string
    {
        return $this->callFood99PeopleServiceMethod(__FUNCTION__, [$json, $directKeys, $nestedKeys]);
    }

    private function resolveFood99RemoteClientId(array $address, array $payload = []): string
    {
        return (string) ($this->callFood99PeopleServiceMethod(__FUNCTION__, [$address, $payload]) ?? '');
    }

    private function syncFood99CourierFromDeliveryState(Order $order, array $deliveryState): ?People
    {
        $courier = $this->callFood99PeopleServiceMethod(__FUNCTION__, [$order, $deliveryState]);

        return $courier instanceof People ? $courier : null;
    }

    private function syncFood99ClientData(
        People $client,
        People $provider,
        array $address,
        string $remoteClientId = ''
    ): People {
        $syncedClient = $this->callFood99PeopleServiceMethod(__FUNCTION__, [$client, $provider, $address, $remoteClientId]);

        return $syncedClient instanceof People ? $syncedClient : $client;
    }

    private function resolveFood99MarketplacePeople(): People
    {
        $people = $this->callFood99FinancialServiceMethod(__FUNCTION__, []);

        if ($people instanceof People) {
            return $people;
        }

        throw new \RuntimeException('Food99 marketplace people could not be resolved.');
    }

    private function persistOrderConfirmResult(Order $order, ?array $response): array
    {
        $result = $this->callFood99ServiceMethod(__FUNCTION__, [$order, $response]);
        if (is_array($result)) {
            return $result;
        }

        return [
            'errno' => 10001,
            'errmsg' => 'Nao foi possivel confirmar o pedido na 99Food.',
            'data' => [],
        ];
    }

    private function syncProviderWebhookReceiptState(array $json): void
    {
        $this->callFood99ServiceMethod(__FUNCTION__, [$json]);
    }

    private function syncStoreStatusWebhook(array $json): void
    {
        $this->callFood99StoreServiceMethod(__FUNCTION__, [$json]);
    }

    private function resolveOrderClient(People $provider, array $address, array $payload, string $orderId): People
    {
        $client = $this->callFood99ServiceMethod(__FUNCTION__, [$provider, $address, $payload, $orderId]);

        return $client instanceof People ? $client : $provider;
    }

    private function decodeOrderOtherInformationsValue(mixed $value): array
    {
        $decoded = $this->callFood99ServiceMethod(__FUNCTION__, [$value]);

        return is_array($decoded) ? $decoded : [];
    }

    private function getDecodedOrderOtherInformations(Order $order): array
    {
        $decoded = $this->callFood99ServiceMethod(__FUNCTION__, [$order]);

        return is_array($decoded) ? $decoded : [];
    }

    private function resolveFood99DeliveryPickupAddress(Order $order): ?Address
    {
        $address = $this->callFood99ServiceMethod(__FUNCTION__, [$order]);

        return $address instanceof Address ? $address : null;
    }

    private function resolveFood99DeliveryDropoffAddress(Order $order): ?Address
    {
        $address = $this->callFood99ServiceMethod(__FUNCTION__, [$order]);

        return $address instanceof Address ? $address : null;
    }

    private function resolveFood99PrimaryAddress(?People $people): ?Address
    {
        $address = $this->callFood99ServiceMethod(__FUNCTION__, [$people]);

        return $address instanceof Address ? $address : null;
    }

    public function performReadyAction(Order $order): array
    {
        $result = $this->callFood99ServiceMethod(__FUNCTION__, [$order]);

        return is_array($result) ? $result : ['errno' => 1, 'errmsg' => 'A acao ready do Food99 nao esta disponivel.'];
    }

    public function performCancelAction(Order $order, ?int $reasonId = null, ?string $reason = null): array
    {
        $result = $this->callFood99ServiceMethod(__FUNCTION__, [$order, $reasonId, $reason]);

        return is_array($result) ? $result : ['errno' => 1, 'errmsg' => 'A acao cancel do Food99 nao esta disponivel.'];
    }

    public function performDeliveredAction(Order $order, ?string $deliveryCode = null, ?string $locator = null): array
    {
        $result = $this->callFood99ServiceMethod(__FUNCTION__, [$order, $deliveryCode, $locator]);

        return is_array($result) ? $result : ['errno' => 1, 'errmsg' => 'A acao delivered do Food99 nao esta disponivel.'];
    }

    private function findFood99DeliveryOrder(Order $order): ?Order
    {
        $deliveryOrder = $this->entityManager->getRepository(Order::class)->findOneBy([
            'mainOrderId' => $order->getId(),
            'orderType' => Order::ORDER_TYPE_DELIVERY,
            'app' => self::APP_CONTEXT,
        ]);

        return $deliveryOrder instanceof Order ? $deliveryOrder : null;
    }

    private function resolveFood99DeliveryOrderStatus(?string $remoteState, ?string $deliveryStatus): Status
    {
        $normalizedRemoteState = $this->normalizeRemoteOrderState($remoteState);
        $normalizedDeliveryStatus = $this->normalizeIncomingFood99Value($deliveryStatus);

        if (
            $this->isCancellationRemoteOrderState($normalizedRemoteState)
            || $normalizedRemoteState === 'finished'
            || $normalizedRemoteState === 'delivered'
            || $normalizedRemoteState === 'closed'
            || $this->isDeliveredRemoteState($normalizedDeliveryStatus)
        ) {
            return $this->statusService->discoveryStatus('closed', 'closed', 'order');
        }

        if ($normalizedRemoteState === 'ready') {
            return $this->statusService->discoveryStatus('pending', 'ready', 'order');
        }

        if (in_array($normalizedRemoteState, ['picked_up', 'delivering', 'arriving'], true)) {
            return $this->statusService->discoveryStatus('pending', 'way', 'order');
        }

        if (in_array($normalizedRemoteState, ['accepted', 'preparing'], true)) {
            return $this->statusService->discoveryStatus('open', 'preparing', 'order');
        }

        return $this->statusService->discoveryStatus('open', 'open', 'order');
    }

    private function syncFood99DeliveryOrder(
        Order $order,
        ?People $courier = null,
        ?string $remoteState = null,
        ?string $deliveryStatus = null
    ): Order {
        $deliveryOrder = $this->findFood99DeliveryOrder($order);
        $deliveryOrderExists = $deliveryOrder instanceof Order;
        if (!$deliveryOrder instanceof Order) {
            $deliveryOrder = new Order();
        }

        $resolvedCourier = $courier instanceof People ? $courier : null;
        if (!$resolvedCourier instanceof People && $deliveryOrder->getDeliveryPeople() instanceof People) {
            $resolvedCourier = $deliveryOrder->getDeliveryPeople();
        }
        if (!$resolvedCourier instanceof People && $order->getDeliveryPeople() instanceof People) {
            $resolvedCourier = $order->getDeliveryPeople();
        }

        $pickupAddress = $this->resolveFood99DeliveryPickupAddress($order);
        $dropoffAddress = $this->resolveFood99DeliveryDropoffAddress($order);
        $marketplacePeople = $this->resolveFood99MarketplacePeople();

        $deliveryOrder->setMainOrder($order);
        $deliveryOrder->setMainOrderId($order->getId());
        if ($resolvedCourier instanceof People) {
            $deliveryOrder->setProvider($resolvedCourier);
            $deliveryOrder->setDeliveryPeople($resolvedCourier);
        }
        $deliveryOrder->setClient($order->getProvider());
        $deliveryOrder->setPayer($marketplacePeople);
        $deliveryOrder->setAddressOrigin($pickupAddress);
        $deliveryOrder->setAddressDestination($dropoffAddress);
        $deliveryOrder->setOtherInformations(new \stdClass());
        $deliveryOrder->setRetrieveContact($order->getRetrieveContact() instanceof People
            ? $order->getRetrieveContact()
            : $order->getProvider());
        $deliveryOrder->setDeliveryContact($order->getClient());
        $deliveryOrder->setComments($order->getComments());
        $deliveryOrder->setOrderType(Order::ORDER_TYPE_DELIVERY);
        $deliveryOrder->setApp(self::APP_CONTEXT);

        $shouldRefreshStatus = !$deliveryOrderExists
            || $remoteState !== null
            || $deliveryStatus !== null
            || !($deliveryOrder->getStatus() instanceof Status);
        if ($shouldRefreshStatus) {
            $deliveryOrder->setStatus($this->resolveFood99DeliveryOrderStatus($remoteState, $deliveryStatus));
        }

        return $deliveryOrder;
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
        return $this->extractFood99PayloadValueFromNestedSections($json, [
            'rider_name',
            'riderName',
            'courier_name',
            'courierName',
            'driver_name',
            'driverName',
        ], [
            'name',
            'full_name',
            'fullName',
            'display_name',
            'displayName',
            'alias',
        ]);
    }

    private function extractOrderRiderPhone(array $json): ?string
    {
        return $this->extractFood99PayloadValueFromNestedSections($json, [
            'rider_phone',
            'riderPhone',
            'courier_phone',
            'courierPhone',
            'driver_phone',
            'driverPhone',
        ], [
            'phone',
            'phone_number',
            'phoneNumber',
            'mobile',
            'mobile_phone',
            'mobilePhone',
            'contact_phone',
            'contactPhone',
        ]);
    }

    private function extractOrderRiderToStoreEta(array $json): ?string
    {
        return $this->extractFood99PayloadValueFromNestedSections($json, [
            'rider_to_B_ETA',
            'rider_to_b_eta',
            'riderToBEta',
            'rider_to_store_eta',
            'riderToStoreEta',
            'courier_to_store_eta',
            'courierToStoreEta',
            'eta_to_store',
            'etaToStore',
        ], [
            'eta',
            'eta_minutes',
            'etaMinutes',
            'arrived_eta',
            'arrivedEta',
            'arrival_eta',
            'arrivalEta',
            'to_store_eta',
            'toStoreEta',
            'to_b_eta',
            'toBEta',
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
        $otherInformations = $this->getDecodedOrderOtherInformations($order);
        $food99Snapshot = $this->flattenFood99SnapshotBlock($otherInformations[self::APP_CONTEXT] ?? []);
        $food99Snapshot[$entryKey] = $payload;
        $food99Snapshot['latest_event_type'] = $entryKey;
        $otherInformations[self::APP_CONTEXT] = $food99Snapshot;
        $order->setOtherInformations($otherInformations);
        $this->entityManager->persist($order);
    }

    private function buildLogContext(?Integration $integration = null, array $json = [], array $extra = []): array
    {
        $context = $this->callFood99ServiceMethod(__FUNCTION__, [$integration, $json, $extra]);
        if (is_array($context)) {
            return $context;
        }

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

    private function flattenFood99SnapshotBlock(mixed $snapshot): array
    {
        if ($snapshot instanceof \stdClass) {
            $snapshot = (array) $snapshot;
        }

        if (!is_array($snapshot)) {
            return [];
        }

        $flattened = [];
        if (isset($snapshot[self::APP_CONTEXT])) {
            $flattened = $this->flattenFood99SnapshotBlock($snapshot[self::APP_CONTEXT]);
        }

        foreach ($snapshot as $key => $value) {
            if ($key === self::APP_CONTEXT) {
                continue;
            }

            $flattened[$key] = $value;
        }

        return $flattened;
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

            'accepted',
            'preparing',
            //'courier_to_store',
            //'arriving' 
            => $this->applyLocalPreparingStatus($order),

            'ready' => $this->applyLocalReadyStatus($order),

            'picked_up',
            'delivering' => $this->applyLocalWayStatus($order),

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
        $storedState = $this->callFood99ServiceMethod('getStoredOrderIntegrationState', [$order]);
        $currentRemoteState = $this->normalizeIncomingFood99Value($storedState['remote_order_state'] ?? null);
        $incomingRemoteState = $this->resolveCanonicalRemoteOrderState($eventType, $deliveryStatus);
        $incomingRemoteState = $this->resolveFallbackRemoteOrderStateForDeliveryEvent(
            $order,
            $eventType,
            $incomingRemoteState,
            $currentRemoteState
        );
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

        $this->callFood99ServiceMethod('persistOrderIntegrationState', [
            $order,
            array_merge($integrationState, $deliveryState),
        ]);

        $courier = $this->syncFood99CourierFromDeliveryState($order, $deliveryState);
        $this->syncFood99DeliveryOrder(
            $order,
            $courier,
            'new',
            $this->extractOrderDeliveryStatus($json)
        );

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

    private function resolveFallbackRemoteOrderStateForDeliveryEvent(
        Order $order,
        string $eventType,
        ?string $incomingRemoteState,
        ?string $currentRemoteState
    ): ?string {
        if ($this->normalizeEventType($eventType) !== 'deliverystatus') {
            return $incomingRemoteState;
        }

        if ($this->normalizeRemoteOrderState($incomingRemoteState) !== '') {
            return $incomingRemoteState;
        }

        $localRealStatus = strtolower(trim((string) ($order->getStatus()?->getRealStatus() ?? '')));
        if (in_array($localRealStatus, ['closed', 'canceled', 'cancelled'], true)) {
            return $incomingRemoteState;
        }

        $normalizedCurrentRemoteState = $this->normalizeRemoteOrderState($currentRemoteState);
        if (in_array($normalizedCurrentRemoteState, ['ready', 'courier_to_store'], true)) {
            self::$logger->info('Food99 deliveryStatus fallback promoted order to picked_up', [
                'local_order_id' => $order->getId(),
                'current_remote_state' => $normalizedCurrentRemoteState,
                'local_real_status' => $localRealStatus,
            ]);
            return 'picked_up';
        }

        $localStatusName = strtolower(trim((string) ($order->getStatus()?->getStatus() ?? '')));
        if ($localRealStatus === 'open' && in_array($localStatusName, ['preparing', 'confirmed'], true)) {
            self::$logger->info('Food99 deliveryStatus fallback promoted preparing order to picked_up', [
                'local_order_id' => $order->getId(),
                'current_remote_state' => $normalizedCurrentRemoteState,
                'local_real_status' => $localRealStatus,
                'local_status' => $localStatusName,
            ]);
            return 'picked_up';
        }

        return $incomingRemoteState;
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
            if ($statusCode === 150) {
                return 'arriving';
            }
            if ($statusCode === 140) {
                return 'picked_up';
            }
            if ($statusCode === 130) {
                return 'courier_to_store';
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
                if ($this->shouldSkipExistingOrderNewRetry($exists)) {
                    self::$logger->info('Food99 existing order is terminal; orderNew retry closed without confirmation', $this->buildLogContext(null, $json, [
                        'local_order_id' => $exists->getId(),
                        'order_code' => $orderCode,
                    ]));

                    return null;
                }

                $this->retryExistingOrderConfirmationIfNeeded($exists, $orderId);

                self::$logger->info('Food99 order already integrated, skipping duplicate creation', $this->buildLogContext(null, $json, [
                    'local_order_id' => $exists->getId(),
                    'dedupe_by' => $orderId !== '' ? 'order_id' : 'order_code',
                ]));
                return $exists;
            }

            $shopId = $this->normalizeIncomingFood99Value($shop['shop_id'] ?? null);

            $provider = null;
            if ($shopId !== '') {
                $provider = $this->extraDataService->getEntityByExtraData(
                    self::APP_CONTEXT,
                    'code',
                    $shopId,
                    People::class
                );
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
                    $this->extraDataService->upsertExtraDataValue(
                        self::APP_CONTEXT,
                        'People',
                        (int) $provider->getId(),
                        'code',
                        $shopId,
                        'text',
                        self::APP_CONTEXT
                    );
                }
            }

            $client = $this->resolveOrderClient($provider, $receiveAddress, $json, $orderId);
            $status = $this->statusService->discoveryStatus('open', 'open', 'order');
            $orderPrice = isset($price['order_price']) ? ((float) $price['order_price']) / 100 : 0.0;

            $order = $this->createOrder($client, $provider, $orderPrice, $status, $json);
            $this->syncOrderComments($order, $this->extractOrderRemark($json));
            $this->entityManager->persist($order);
            $this->entityManager->flush();

            $this->extraDataService->upsertExtraDataValue(
                self::APP_CONTEXT,
                'Order',
                (int) $order->getId(),
                'id',
                $orderId,
                'text',
                self::APP_CONTEXT
            );
            $this->extraDataService->upsertExtraDataValue(
                self::APP_CONTEXT,
                'Order',
                (int) $order->getId(),
                'code',
                $orderCode,
                'text',
                self::APP_CONTEXT
            );
            $deliveryState = $this->extractOrderDeliveryStateFields($json);
            $remoteState = 'new';
            $deliveryStatus = $this->extractOrderDeliveryStatus($json);

            self::$logger->info('Food99 order delivery state resolved', $this->buildLogContext(null, $json, [
                'local_order_id' => $order->getId(),
                'remote_state' => $remoteState,
                'delivery_status' => $deliveryStatus,
                'delivery_type' => $deliveryState['delivery_type'] ?? null,
                'fulfillment_mode' => $deliveryState['fulfillment_mode'] ?? null,
            ]));

            $this->callFood99ServiceMethod('persistOrderIntegrationState', [
                $order,
                array_merge([
                    'last_event_type' => 'orderNew',
                    'last_event_at' => $this->extractOrderEventTimestamp($json),
                    'remote_order_state' => $remoteState,
                    'remote_delivery_status' => $deliveryStatus,
                ], $deliveryState),
            ]);

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
            $courier = $this->syncFood99CourierFromDeliveryState($order, $deliveryState);
            $this->syncFood99DeliveryOrder(
                $order,
                $courier,
                $remoteState,
                $deliveryStatus
            );

            $this->entityManager->persist($order);
            $this->entityManager->flush();

            self::$logger->info('Food99 order persisted locally', $this->buildLogContext(null, $json, [
                'provider_id' => $provider?->getId(),
                'client_id' => $client?->getId(),
                'local_order_id' => $order->getId(),
            ]));

            // O financeiro do marketplace e reconstruido pelo gerador central usando otherInformations.

            $confirmResult = $this->confirmOrder($order, $orderId, $provider);
            $this->throwIfConfirmationShouldRetry($confirmResult, $orderId, $order);
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
        $client = $this->resolveFood99Client();
        if (!$client) {
            $message = 'Token de acesso indisponivel para confirmar pedido na 99Food.';
            self::$logger->warning('Food99 confirm skipped because client is unavailable', [
                'order_id' => $orderId,
                'provider_id' => $provider?->getId(),
                'message' => $message,
            ]);

            return $this->persistOrderConfirmResult($order, null);
        }

        $response = $client->callOrderEndpointWithResponse('/v1/order/order/confirm', [
            'order_id' => $orderId,
        ], $provider);

        if (!is_array($response)) {
            return $this->persistOrderConfirmResult($order, null);
        }

        return $this->persistOrderConfirmResult($order, $response);
    }

    private function shouldSkipExistingOrderNewRetry(Order $order): bool
    {
        if ($this->isTerminalLocalOrderStatus($order)) {
            return true;
        }

        $state = $this->getStoredOrderIntegrationState($order);
        return $this->isTerminalRemoteOrderState($state['remote_order_state'] ?? null);
    }

    private function retryExistingOrderConfirmationIfNeeded(Order $order, string $orderId): void
    {
        $state = $this->getStoredOrderIntegrationState($order);
        if ($this->isSuccessfulErrno($state['confirm_errno'] ?? null)) {
            return;
        }

        $confirmResult = $this->confirmOrder($order, $orderId, $order->getProvider());
        $this->throwIfConfirmationShouldRetry($confirmResult, $orderId, $order);
    }

    private function throwIfConfirmationShouldRetry(array $confirmResult, string $orderId, Order $order): void
    {
        if ($this->isSuccessfulErrno($confirmResult['errno'] ?? null)) {
            return;
        }

        if ($this->isUnavailableOrderActionResponse($confirmResult)) {
            self::$logger?->warning('Food99 order confirmation skipped because the confirmation service is unavailable', [
                'order_id' => $orderId,
                'local_order_id' => $order->getId(),
                'message' => $confirmResult['errmsg'] ?? null,
            ]);

            return;
        }

        if ($this->isTerminalLocalOrderStatus($order)) {
            return;
        }

        throw new \RuntimeException(sprintf(
            'Food99 order confirmation failed for remote order %s: %s',
            $orderId,
            trim((string) ($confirmResult['errmsg'] ?? 'unknown error'))
        ));
    }

    private function isUnavailableOrderActionResponse(array $response): bool
    {
        return (int) ($response['errno'] ?? 0) === 10001;
    }

    /**
     * A geração financeira foi centralizada no pipeline de marketplace.
     * Este método fica apenas como compatibilidade para evitar recriar invoices inline.
     */
    private function addPayments(Order $order, array $data): void
    {
        self::$logger->info('Food99 legacy inline payment generation skipped; invoices are generated from the persisted order snapshot by the marketplace financial pipeline.', [
            'order_id' => $order->getId(),
        ]);
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

                $productGroup = $this->resolveIncomingProductGroup($parentProduct, $product, $item);

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

        $product = $this->extraDataService->getEntityByExtraData(
            self::APP_CONTEXT,
            'code',
            $code,
            Product::class
        );

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

        if ($parentProduct && $this->hasIncomingProductGroupReference($item)) {
            $group = $this->resolveIncomingProductGroup($parentProduct, $product, $item, $productType);

            if ($group instanceof ProductGroup) {
                $pgpRepository = $this->entityManager->getRepository(ProductGroupProduct::class);
                $quantity = (float) ($item['amount'] ?? 1);
                $pgp = $pgpRepository->findSharedGroupItem($group, $product, $productType, $quantity);

                if (!$pgp instanceof ProductGroupProduct) {
                    $pgp = new ProductGroupProduct();
                    $pgp->setProductChild($product);
                    $pgp->setProductGroup($group);
                    $pgp->setProductType($productType);
                    $pgp->setProduct($productType === 'feedstock' ? $parentProduct : null);
                    $this->entityManager->persist($pgp);
                }

                $pgp->setQuantity($quantity);
                $pgp->setPrice(isset($item['sku_price']) ? ((float) $item['sku_price']) / 100 : 0);
                $pgp->setProduct($productType === 'feedstock' ? $parentProduct : null);

                $this->entityManager->flush();
            }
        }

        $this->extraDataService->upsertExtraDataValue(
            self::APP_CONTEXT,
            'Product',
            (int) $product->getId(),
            'code',
            (string) $code,
            'text',
            self::APP_CONTEXT
        );

        return $product;
    }

    private function resolveIncomingProductGroup(
        ?Product $parentProduct,
        Product $product,
        array $item,
        ?string $productType = null
    ): ?ProductGroup
    {
        if (!$parentProduct instanceof Product) {
            return null;
        }

        if ($this->hasIncomingProductGroupReference($item)) {
            $resolvedGroupName = $this->resolveModifierGroupName(
                $item['app_content_id'] ?? '',
                $item['content_name'] ?? ''
            );

            return $this->productGroupService->discoveryProductGroup(
                $parentProduct,
                $resolvedGroupName
            );
        }

        $catalogLink = $this->findProductGroupProductLink(
            $parentProduct,
            $product,
            $productType,
            (float) ($item['amount'] ?? 1)
        );

        return $catalogLink?->getProductGroup();
    }

    private function hasIncomingProductGroupReference(array $item): bool
    {
        return trim((string) ($item['app_content_id'] ?? '')) !== ''
            || trim((string) ($item['content_name'] ?? '')) !== '';
    }

    private function findProductGroupProductLink(
        Product $parentProduct,
        Product $product,
        ?string $productType = null,
        ?float $quantity = null
    ): ?ProductGroupProduct
    {
        $link = $this->entityManager
            ->getRepository(ProductGroupProduct::class)
            ->findLinkedGroupItemForParent($parentProduct, $product, $productType, $quantity);

        if (!$link instanceof ProductGroupProduct || !$link->getProductGroup() instanceof ProductGroup) {
            return null;
        }

        return $link;
    }

    private function discoveryClient(array $address, array $payload = [], ?People $provider = null): ?People
    {
        $remoteClientId = $this->resolveFood99RemoteClientId($address, $payload);

        if ($remoteClientId === '') {
            return null;
        }

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

        return null;
    }

    private function findFood99ClientByAddressAndName(array $address, string $resolvedName): ?People
    {
        $normalizedName = strtolower(trim($resolvedName));
        if ($normalizedName === '') {
            return null;
        }

        $streetNumber = $address['street_number'] ?? $address['house_number'] ?? null;
        $streetNumber = is_numeric($streetNumber) ? (int) $streetNumber : null;

        $city = trim((string) ($address['city'] ?? ''));
        $state = trim((string) ($address['state'] ?? ''));
        $countryCode = strtoupper(trim((string) ($address['country_code'] ?? '')));
        $district = trim((string) ($address['district'] ?? ''));
        $street = trim((string) ($address['street_name'] ?? ''));

        if ($city === '' || $state === '' || $countryCode === '' || $district === '' || $street === '' || $streetNumber === null) {
            return null;
        }

        $matchedAddresses = $this->entityManager->getRepository(Address::class)
            ->createQueryBuilder('a')
            ->innerJoin('a.people', 'p')
            ->innerJoin('a.street', 's')
            ->innerJoin('s.district', 'd')
            ->innerJoin('d.city', 'c')
            ->innerJoin('c.state', 'e')
            ->innerJoin('e.country', 'u')
            ->andWhere('LOWER(TRIM(p.name)) = :name')
            ->andWhere('u.countrycode = :countryCode')
            ->andWhere('(e.uf = :state OR e.state = :state)')
            ->andWhere('c.city = :city')
            ->andWhere('d.district = :district')
            ->andWhere('s.street = :street')
            ->andWhere('a.number = :number')
            ->setParameter('name', $normalizedName)
            ->setParameter('countryCode', $countryCode)
            ->setParameter('state', $state)
            ->setParameter('city', $city)
            ->setParameter('district', $district)
            ->setParameter('street', $street)
            ->setParameter('number', $streetNumber)
            ->getQuery()
            ->getResult();

        if (!is_array($matchedAddresses) || empty($matchedAddresses)) {
            return null;
        }

        foreach ($matchedAddresses as $matchedAddress) {
            if (!$matchedAddress instanceof Address || !$matchedAddress->getPeople() instanceof People) {
                continue;
            }

            return $matchedAddress->getPeople();
        }

        return null;
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

    private function isReadyQueueTransition(OrderProductQueue $oldQueue, OrderProductQueue $newQueue): bool
    {
        $statusOutId = (int) ($newQueue->getQueue()?->getStatusOut()?->getId() ?? 0);
        if ($statusOutId <= 0) {
            return false;
        }

        $oldStatusId = (int) ($oldQueue->getStatus()?->getId() ?? 0);
        $newStatusId = (int) ($newQueue->getStatus()?->getId() ?? 0);

        return $oldStatusId > 0
            && $oldStatusId !== $statusOutId
            && $newStatusId === $statusOutId;
    }

    private function areAllOrderProductQueuesReady(Order $order): bool
    {
        $hasQueueEntry = false;

        foreach ($order->getOrderProducts() as $orderProduct) {
            foreach ($orderProduct->getOrderProductQueues() as $queueEntry) {
                $hasQueueEntry = true;
                $statusOutId = (int) ($queueEntry->getQueue()?->getStatusOut()?->getId() ?? 0);
                $currentStatusId = (int) ($queueEntry->getStatus()?->getId() ?? 0);

                if ($statusOutId <= 0 || $currentStatusId !== $statusOutId) {
                    return false;
                }
            }
        }

        return $hasQueueEntry;
    }

    private function handleOrderProductQueueReadyTransition(OrderProductQueue $oldQueue, OrderProductQueue $newQueue): void
    {
        if (!$this->isReadyQueueTransition($oldQueue, $newQueue)) {
            return;
        }

        $order = $newQueue->getOrderProduct()?->getOrder();
        if (!$order instanceof Order || $order->getApp() !== self::APP_CONTEXT) {
            return;
        }

        $realStatus = strtolower(trim((string) ($order->getStatus()?->getRealStatus() ?? '')));
        if ($realStatus !== 'open' || !$this->areAllOrderProductQueuesReady($order)) {
            return;
        }

        $this->performReadyAction($order);
    }

    public function onEntityChanged(EntityChangedEvent $event)
    {
        $oldEntity = $event->getOldEntity();
        $entity = $event->getEntity();

        if ($entity instanceof OrderProductQueue && $oldEntity instanceof OrderProductQueue) {
            $this->handleOrderProductQueueReadyTransition($oldEntity, $entity);
            return;
        }

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
            default => null,
        };

        return null;
    }
}
