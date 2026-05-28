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
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\Service\Attribute\Required;

class Food99OrderOperationsService extends AbstractMarketplaceService
{
    private const APP_CONTEXT = Order::APP_FOOD99;
    private const OPEN_DELIVERY_SYNC_EVENT_TYPES = [
        'CREATED',
        'CONFIRMED',
        'READY_FOR_PICKUP',
        'DISPATCHED',
        'DELIVERED',
        'CONCLUDED',
        'CANCELLATION_REQUESTED',
        'CANCELLATION_REQUEST_DENIED',
        'CANCELLED',
        'ORDER_CANCELLATION_REQUEST',
        'CANCELLED_DENIED',
    ];

    private ?Food99Service $food99Service = null;
    private ?Food99PeopleOperationsService $food99PeopleOperationsService = null;
    private ?Food99FinancialOperationsService $food99FinancialOperationsService = null;
    private ?Food99StoreOperationsService $food99StoreOperationsService = null;

    protected function getMarketplaceApp(): string
    {
        return self::APP_CONTEXT;
    }

    #[Required]
    public function setFood99Service(Food99Service $food99Service): void
    {
        $this->food99Service = $food99Service;
    }

    #[Required]
    public function setFood99PeopleOperationsService(Food99PeopleOperationsService $food99PeopleOperationsService): void
    {
        $this->food99PeopleOperationsService = $food99PeopleOperationsService;
    }

    #[Required]
    public function setFood99FinancialOperationsService(Food99FinancialOperationsService $food99FinancialOperationsService): void
    {
        $this->food99FinancialOperationsService = $food99FinancialOperationsService;
    }

    #[Required]
    public function setFood99StoreOperationsService(Food99StoreOperationsService $food99StoreOperationsService): void
    {
        $this->food99StoreOperationsService = $food99StoreOperationsService;
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
        $service = $this->food99Service;
        if (is_object($service) && method_exists($service, 'extractIncomingOrderIdentifiers')) {
            $result = $service->extractIncomingOrderIdentifiers($json);
            if (is_array($result)) {
                return $result;
            }
        }

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
            'order_code' => $orderIndex !== '' ? $orderIndex : $orderId,
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
        $service = $this->food99Service;
        if ($orderId !== '') {
            $order = $this->findFood99OrderByLegacyAwareExtraData('id', $orderId);
            if ($order instanceof Order) {
                return $order;
            }

            if (is_object($service) && method_exists($service, 'findFood99OrderByStoredIntegrationState')) {
                $storedOrder = $service->findFood99OrderByStoredIntegrationState($orderId, $orderCode);
            } else {
                $storedOrder = $this->findFood99OrderByStoredIntegrationState($orderId, $orderCode);
            }
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

    private function getStoredOrderIntegrationState(Order $order): array
    {
        $service = $this->food99Service;
        if (is_object($service) && method_exists($service, 'getStoredOrderIntegrationState')) {
            $storedState = $service->getStoredOrderIntegrationState($order);
            if (is_array($storedState)) {
                return $storedState;
            }
        }

        return [];
    }

    private function normalizeCancelReasonId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits !== '' ? (int) $digits : null;
    }

    private function findFood99OrderByStoredIntegrationState(string $orderId, string $orderCode = ''): ?Order
    {
        $orderId = trim($orderId);
        $orderCode = trim($orderCode);

        if ($orderId === '' && $orderCode === '') {
            return null;
        }

        $needle = $orderId !== '' ? $orderId : $orderCode;
        $candidates = $this->entityManager
            ->getRepository(Order::class)
            ->createQueryBuilder('o')
            ->andWhere('o.app = :app')
            ->andWhere('o.otherInformations LIKE :needle')
            ->setParameter('app', self::APP_CONTEXT)
            ->setParameter('needle', '%' . $needle . '%')
            ->orderBy('o.alterDate', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setMaxResults(25)
            ->getQuery()
            ->getResult();

        foreach ($candidates as $candidate) {
            if (!$candidate instanceof Order) {
                continue;
            }

            $state = $this->getStoredOrderIntegrationState($candidate);
            $candidateOrderId = trim((string) ($state['food99_id'] ?? ''));
            $candidateOrderCode = trim((string) ($state['food99_code'] ?? ''));

            if ($orderId !== '' && $candidateOrderId === $orderId) {
                return $candidate;
            }

            if ($orderCode !== '' && $candidateOrderCode === $orderCode) {
                return $candidate;
            }
        }

        return null;
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

        $otherInformations = $this->getDecodedOrderOtherInformations($order);
        $currentState = $this->decodeOrderOtherInformationsValue($otherInformations[self::APP_CONTEXT] ?? null);
        $otherInformations[self::APP_CONTEXT] = array_merge($currentState, $normalizedFields);
        $order->setOtherInformations($otherInformations);
        $this->entityManager->persist($order);
    }

    public function resolveFood99RemoteClientId(array $address, array $payload = []): string
    {
        $service = $this->food99PeopleOperationsService;
        if (!$service instanceof Food99PeopleOperationsService) {
            return '';
        }

        return (string) $service->resolveFood99RemoteClientId($address, $payload);
    }

    public function syncFood99CourierFromDeliveryState(Order $order, array $deliveryState): ?People
    {
        $service = $this->food99PeopleOperationsService;
        if (!$service instanceof Food99PeopleOperationsService) {
            return null;
        }

        return $service->syncFood99CourierFromDeliveryState($order, $deliveryState);
    }

    private function syncFood99ClientData(
        People $client,
        People $provider,
        array $address,
        string $remoteClientId = ''
    ): People {
        $service = $this->food99PeopleOperationsService;
        if (!is_object($service) || !method_exists($service, 'syncFood99ClientData')) {
            return $client;
        }

        $syncedClient = $service->syncFood99ClientData($client, $provider, $address, $remoteClientId);

        return $syncedClient instanceof People ? $syncedClient : $client;
    }

    private function resolveFood99MarketplacePeople(): People
    {
        $service = $this->food99FinancialOperationsService;
        $people = $service instanceof Food99FinancialOperationsService ? $service->resolveFood99MarketplacePeople() : null;

        if ($people instanceof People) {
            return $people;
        }

        throw new \RuntimeException('Food99 marketplace people could not be resolved.');
    }

    private function persistOrderConfirmResult(Order $order, ?array $response): array
    {
        $service = $this->food99Service;
        if (is_object($service) && method_exists($service, 'persistOrderConfirmResult')) {
            $delegated = $service->persistOrderConfirmResult($order, $response);
            if (is_array($delegated)) {
                return $delegated;
            }
        }

        $safeResponse = is_array($response)
            ? $response
            : [
                'errno' => 10001,
                'errmsg' => 'Nao foi possivel confirmar o pedido na 99Food.',
                'data' => [],
            ];

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

    private function syncProviderWebhookReceiptState(array $json): void
    {
        $service = $this->food99Service;
        if (is_object($service) && method_exists($service, 'syncProviderWebhookReceiptState')) {
            $service->syncProviderWebhookReceiptState($json);
            return;
        }

        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $info = is_array($data['order_info'] ?? null) ? $data['order_info'] : [];
        $shop = is_array($info['shop'] ?? null) ? $info['shop'] : (is_array($data['shop'] ?? null) ? $data['shop'] : []);
        $candidateShopIds = array_values(array_unique(array_filter([
            $this->normalizeIncomingFood99Value($shop['shop_id'] ?? null),
            $this->normalizeIncomingFood99Value($data['shop_id'] ?? null),
            $this->normalizeIncomingFood99Value($json['app_shop_id'] ?? null),
        ], static fn(string $value): bool => $value !== '')));

        $provider = null;
        foreach ($candidateShopIds as $candidateShopId) {
            $provider = $this->extraDataService->getEntityByExtraData(
                self::APP_CONTEXT,
                'code',
                $candidateShopId,
                People::class
            );

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
            return;
        }

        $eventId = $this->normalizeIncomingFood99Value(
            $json['event_id']
                ?? $json['eventId']
                ?? $json['id']
                ?? $json['requestId']
                ?? null
        );
        $eventType = $this->normalizeIncomingFood99Value($json['type'] ?? null);
        $receivedAt = date('Y-m-d H:i:s');
        $orderIdentifiers = $this->extractIncomingOrderIdentifiers($json);
        $fields = [
            'last_webhook_event_type' => $eventType,
            'last_webhook_event_at' => date('Y-m-d H:i:s'),
            'last_webhook_received_at' => $receivedAt,
            'last_webhook_processed_at' => date('Y-m-d H:i:s'),
        ];

        if ($eventId !== '') {
            $fields['last_webhook_event_id'] = $eventId;
        }
        if (($orderIdentifiers['order_id'] ?? '') !== '') {
            $fields['last_webhook_order_id'] = $orderIdentifiers['order_id'];
        }
        if (!empty($candidateShopIds[0])) {
            $fields['last_webhook_shop_id'] = $candidateShopIds[0];
        }

        $storeService = $this->food99StoreOperationsService;
        if ($storeService instanceof Food99StoreOperationsService) {
            $storeService->persistProviderIntegrationState($provider, $fields);
        }
    }

    private function syncStoreStatusWebhook(array $json): void
    {
        $service = $this->food99Service;
        if (is_object($service) && method_exists($service, 'syncStoreStatusWebhook')) {
            $service->syncStoreStatusWebhook($json);
            return;
        }

        $storeService = $this->food99StoreOperationsService;
        if ($storeService instanceof Food99StoreOperationsService) {
            $storeService->syncStoreStatusWebhook($json);
        }
    }

    private function resolveOrderClient(People $provider, array $address, array $payload, string $orderId): People
    {
        $service = $this->food99Service;
        if (is_object($service) && method_exists($service, 'resolveOrderClient')) {
            $resolved = $service->resolveOrderClient($provider, $address, $payload, $orderId);
            if ($resolved instanceof People) {
                return $resolved;
            }
        }

        $client = $this->discoveryClient($address, $payload, $provider);
        if ($client instanceof People) {
            $this->peopleService->discoveryLink($provider, $client, 'client');
            return $client;
        }

        $peopleService = $this->food99PeopleOperationsService;
        $fallbackName = is_object($peopleService) && method_exists($peopleService, 'resolveFood99CustomerName')
            ? (string) $peopleService->resolveFood99CustomerName($address)
            : '';
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

        self::$logger?->warning('Food99 order received without an exact mapped customer code; creating a fresh customer record after exact name/address lookup failed', [
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

    private function decodeOrderOtherInformationsValue(mixed $value): array
    {
        return $this->decodeEntityOtherInformationsValue($value);
    }

    private function getDecodedOrderOtherInformations(Order $order): array
    {
        return $this->getDecodedEntityOtherInformations($order);
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

    public function performReadyAction(Order $order): array
    {
        $service = $this->food99Service;
        $result = $service instanceof Food99Service ? $service->performReadyAction($order) : null;

        return is_array($result) ? $result : ['errno' => 1, 'errmsg' => 'A acao ready do Food99 nao esta disponivel.'];
    }

    public function performCancelAction(Order $order, ?int $reasonId = null, ?string $reason = null): array
    {
        $service = $this->food99Service;
        $result = $service instanceof Food99Service ? $service->performCancelAction($order, $reasonId, $reason) : null;

        return is_array($result) ? $result : ['errno' => 1, 'errmsg' => 'A acao cancel do Food99 nao esta disponivel.'];
    }

    public function performDeliveredAction(Order $order, ?string $deliveryCode = null, ?string $locator = null): array
    {
        $service = $this->food99Service;
        $result = $service instanceof Food99Service ? $service->performDeliveredAction($order, $deliveryCode, $locator) : null;

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

    public function syncFood99DeliveryOrder(
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
        $service = $this->food99PeopleOperationsService;
        $timestamp = $service instanceof Food99PeopleOperationsService
            ? $service->searchPayloadValueByKeys($json, [
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
        ])
            : null;

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
        $service = $this->food99PeopleOperationsService;
        if (!$service instanceof Food99PeopleOperationsService) {
            return null;
        }

        return $service->searchPayloadValueByKeys($json, [
            'delivery_status',
            'deliveryStatus',
            'status_desc',
            'statusDesc',
            'status',
        ]);
    }

    private function extractOrderDeliveryType(array $json): ?string
    {
        $service = $this->food99PeopleOperationsService;
        if (!$service instanceof Food99PeopleOperationsService) {
            return null;
        }

        return $service->searchPayloadValueByKeys($json, [
            'delivery_type',
            'deliveryType',
        ]);
    }

    private function extractOrderFulfillmentMode(array $json): ?string
    {
        $service = $this->food99PeopleOperationsService;
        if (!$service instanceof Food99PeopleOperationsService) {
            return null;
        }

        return $service->searchPayloadValueByKeys($json, [
            'fulfillment_mode',
            'fulfillmentMode',
        ]);
    }

    private function extractOrderExpectedArrivedEta(array $json): ?string
    {
        $service = $this->food99PeopleOperationsService;
        if (!$service instanceof Food99PeopleOperationsService) {
            return null;
        }

        return $service->searchPayloadValueByKeys($json, [
            'expected_arrived_eta',
            'expectedArrivedEta',
            'delivery_eta',
            'deliveryEta',
        ]);
    }

    private function extractOrderPickupCode(array $json): ?string
    {
        $service = $this->food99PeopleOperationsService;
        if (!$service instanceof Food99PeopleOperationsService) {
            return null;
        }

        return $service->searchPayloadValueByKeys($json, [
            'pickup_code',
            'pickupCode',
        ]);
    }

    private function extractOrderLocator(array $json): ?string
    {
        $service = $this->food99PeopleOperationsService;
        if (!$service instanceof Food99PeopleOperationsService) {
            return null;
        }

        return $service->searchPayloadValueByKeys($json, [
            'locator',
        ]);
    }

    private function extractOrderHandoverPageUrl(array $json): ?string
    {
        $service = $this->food99PeopleOperationsService;
        if (!$service instanceof Food99PeopleOperationsService) {
            return null;
        }

        return $service->searchPayloadValueByKeys($json, [
            'handover_page_url',
            'handoverPageUrl',
        ]);
    }

    private function extractOrderVirtualPhoneNumber(array $json): ?string
    {
        $service = $this->food99PeopleOperationsService;
        if (!$service instanceof Food99PeopleOperationsService) {
            return null;
        }

        return $service->searchPayloadValueByKeys($json, [
            'virtual_phone_number',
            'virtualPhoneNumber',
        ]);
    }

    private function extractOrderHandoverCode(array $json): ?string
    {
        $service = $this->food99PeopleOperationsService;
        if (!$service instanceof Food99PeopleOperationsService) {
            return null;
        }

        return $service->searchPayloadValueByKeys($json, [
            'handover_code',
            'handoverCode',
        ]);
    }

    public function extractOrderRiderName(array $json): ?string
    {
        $service = $this->food99PeopleOperationsService;
        if (!$service instanceof Food99PeopleOperationsService) {
            return null;
        }

        return $service->extractFood99PayloadValueFromNestedSections($json, [
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

    public function extractOrderRiderPhone(array $json): ?string
    {
        $service = $this->food99PeopleOperationsService;
        if (!$service instanceof Food99PeopleOperationsService) {
            return null;
        }

        return $service->extractFood99PayloadValueFromNestedSections($json, [
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

    public function extractOrderRiderToStoreEta(array $json): ?string
    {
        $service = $this->food99PeopleOperationsService;
        if (!$service instanceof Food99PeopleOperationsService) {
            return null;
        }

        return $service->extractFood99PayloadValueFromNestedSections($json, [
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
        $service = $this->food99PeopleOperationsService;
        if (!$service instanceof Food99PeopleOperationsService) {
            return null;
        }

        return $service->searchPayloadValueByKeys($json, [
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
        $service = $this->food99PeopleOperationsService;
        if (!$service instanceof Food99PeopleOperationsService) {
            return null;
        }

        return $service->searchPayloadValueByKeys($json, [
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

    public function storeOrderRemoteSnapshot(Order $order, string $entryKey, array $payload): void
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

    public function applyLocalLifecycleStatusFromRemoteState(Order $order, ?string $remoteState): void
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
        $storedState = $this->getStoredOrderIntegrationState($order);
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

        $this->persistOrderIntegrationState($order, array_merge($integrationState, $deliveryState));

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

    public function resolveFallbackRemoteOrderStateForDeliveryEvent(
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

    public function resolveRemoteOrderStateFromDeliveryStatus(?string $deliveryStatus): ?string
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

    private function addOrder(
        array $json,
        bool $autoConfirm = true,
        bool $autoPrint = true,
        ?People $provider = null
    ): ?Order
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

            $provider = $provider instanceof People ? $provider : null;

            if (!($provider instanceof People) && $shopId !== '') {
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
            }

            if ($provider instanceof People && $shopId !== '') {
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

            $this->persistOrderIntegrationState($order, array_merge([
                'last_event_type' => 'orderNew',
                'last_event_at' => $this->extractOrderEventTimestamp($json),
                'remote_order_state' => $remoteState,
                'remote_delivery_status' => $deliveryStatus,
            ], $deliveryState));

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

            if ($autoConfirm) {
                $confirmResult = $this->confirmOrder($order, $orderId, $provider);
                $this->throwIfConfirmationShouldRetry($confirmResult, $orderId, $order);
            }

            if ($autoPrint) {
                $this->printOrder($order);
            }

            return $order;
        } finally {
            if ($lockAcquired) {
                $this->releaseOrderIntegrationLock($orderId);
            }
        }
    }

    public function syncOrdersFromPolling(
        People $provider,
        ?string $fromTime = null,
        array $eventTypes = []
    ): array {
        $this->init();

        $client = $this->resolveFood99Client();
        if (!$client) {
            return [
                'errno' => 10001,
                'errmsg' => 'Token de acesso indisponivel para sincronizar pedidos na 99Food.',
                'data' => [],
            ];
        }

        $resolvedFromTime = $this->resolveOpenDeliverySyncFromTime($fromTime);
        $resolvedEventTypes = $this->resolveOpenDeliveryEventTypes($eventTypes);

        self::$logger?->info('Food99 open delivery sync started', [
            'provider_id' => $provider->getId(),
            'from_time' => $resolvedFromTime,
            'event_types' => $resolvedEventTypes,
        ]);

        $pollResponse = $client->pollOpenDeliveryEvents($provider, $resolvedEventTypes, $resolvedFromTime);
        if (!is_array($pollResponse)) {
            return [
                'errno' => 10001,
                'errmsg' => 'Nao foi possivel consultar os eventos da 99Food.',
                'data' => [],
            ];
        }

        $eventList = $this->extractOpenDeliveryEvents($pollResponse);
        $normalizedEventsById = [];

        foreach ($eventList as $event) {
            $normalizedEvent = $this->normalizeOpenDeliveryEvent($event);
            if (!$normalizedEvent) {
                continue;
            }

            $eventId = $normalizedEvent['event_id'];
            if (!isset($normalizedEventsById[$eventId]) || $normalizedEvent['created_at_ts'] >= ($normalizedEventsById[$eventId]['created_at_ts'] ?? 0)) {
                $normalizedEventsById[$eventId] = $normalizedEvent;
            }
        }

        $normalizedEvents = array_values($normalizedEventsById);
        usort($normalizedEvents, static function (array $left, array $right): int {
            $comparison = ($left['created_at_ts'] ?? 0) <=> ($right['created_at_ts'] ?? 0);
            if ($comparison !== 0) {
                return $comparison;
            }

            return strcmp((string) ($left['event_id'] ?? ''), (string) ($right['event_id'] ?? ''));
        });

        $groupedEvents = [];
        foreach ($normalizedEvents as $event) {
            $groupedEvents[$event['order_id']][] = $event;
        }

        $processedOrders = [];
        $failedOrders = [];
        $processedEvents = 0;
        $ackEvents = [];

        foreach ($groupedEvents as $orderId => $orderEvents) {
            try {
                $orderDetails = $client->getOpenDeliveryOrderDetails($provider, $orderId);
                if (!is_array($orderDetails)) {
                    $failedOrders[] = [
                        'order_id' => $orderId,
                        'reason' => 'Nao foi possivel carregar os detalhes do pedido.',
                    ];

                    continue;
                }

                $order = null;
                $orderFailed = false;
                $lastMappedEventType = null;

                foreach ($orderEvents as $event) {
                    $payload = $this->buildOpenDeliveryWebhookPayload($provider, $orderDetails, $event);
                    $this->syncProviderWebhookReceiptState($payload);

                    if (!$order instanceof Order) {
                        $order = $this->addOrder($payload, false, false, $provider);
                        if (!$order instanceof Order) {
                            $existingOrder = $this->findExistingIntegratedOrder($orderId, '', true);
                            if ($existingOrder instanceof Order) {
                                $order = $existingOrder;
                            } else {
                                $orderFailed = true;
                                $failedOrders[] = [
                                    'order_id' => $orderId,
                                    'event_id' => $event['event_id'],
                                    'reason' => 'Nao foi possivel criar o pedido localmente.',
                                ];
                                break;
                            }
                        }
                    }

                    $mappedEventType = (string) ($event['mapped_event_type'] ?? '');
                    if ($mappedEventType !== '') {
                        $updatedOrder = $this->handleGenericOrderEvent($payload, $mappedEventType, false);
                        if ($updatedOrder instanceof Order) {
                            $order = $updatedOrder;
                        }
                    }

                    $ackEvents[] = [
                        'id' => $event['event_id'],
                        'orderId' => $orderId,
                        'eventType' => $event['original_event_type'],
                    ];
                    $processedEvents++;
                    $lastMappedEventType = $mappedEventType !== '' ? $mappedEventType : $lastMappedEventType;
                }

                if ($orderFailed) {
                    continue;
                }

                $processedOrders[] = [
                    'order_id' => $orderId,
                    'local_order_id' => $order instanceof Order ? $order->getId() : null,
                    'event_count' => count($orderEvents),
                    'last_event_type' => $lastMappedEventType,
                    'status' => $order instanceof Order ? $order->getStatus()?->getRealStatus() : null,
                ];
            } catch (\Throwable $exception) {
                $failedOrders[] = [
                    'order_id' => $orderId,
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        $acknowledgedCount = 0;
        $ackResponse = null;
        if ($ackEvents !== []) {
            $ackResponse = $client->acknowledgeOpenDeliveryEvents($provider, $ackEvents);
            if (is_array($ackResponse) && $this->isSuccessfulErrno($ackResponse['errno'] ?? null)) {
                $acknowledgedCount = count($ackEvents);
            } else {
                self::$logger?->warning('Food99 open delivery acknowledgment failed', [
                    'provider_id' => $provider->getId(),
                    'response' => $ackResponse,
                ]);
            }
        }

        $storeService = $this->food99StoreOperationsService;
        if ($storeService instanceof Food99StoreOperationsService) {
            $storeService->persistProviderIntegrationState($provider, [
                'last_sync_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return [
            'errno' => 0,
            'errmsg' => '',
            'data' => [
                'from_time' => $resolvedFromTime,
                'event_types' => $resolvedEventTypes,
                'polled_event_count' => count($normalizedEvents),
                'unique_order_count' => count($groupedEvents),
                'processed_order_count' => count($processedOrders),
                'failed_order_count' => count($failedOrders),
                'processed_event_count' => $processedEvents,
                'acknowledged_event_count' => $acknowledgedCount,
                'failed_acknowledged_count' => max(0, count($ackEvents) - $acknowledgedCount),
                'orders' => $processedOrders,
                'errors' => $failedOrders,
                'ack_response' => $ackResponse,
            ],
        ];
    }

    private function resolveOpenDeliverySyncFromTime(?string $fromTime): string
    {
        try {
            if (trim((string) $fromTime) !== '') {
                $reference = new DateTimeImmutable(trim((string) $fromTime));
            } else {
                $reference = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))
                    ->setTime(0, 0, 0);
            }
        } catch (\Throwable) {
            $reference = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))
                ->setTime(0, 0, 0);
        }

        return $reference
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DateTimeInterface::ATOM);
    }

    private function resolveOpenDeliveryEventTypes(array $eventTypes): array
    {
        $resolved = array_values(array_unique(array_filter(array_map(
            static fn (mixed $eventType): string => strtoupper(trim((string) $eventType)),
            $eventTypes
        ))));

        return $resolved !== [] ? $resolved : self::OPEN_DELIVERY_SYNC_EVENT_TYPES;
    }

    private function extractOpenDeliveryEvents(array $response): array
    {
        foreach ([
            $response,
            $response['data'] ?? null,
            $response['events'] ?? null,
            $response['eventList'] ?? null,
            $response['items'] ?? null,
            $response['result'] ?? null,
        ] as $candidate) {
            $events = $this->extractOpenDeliveryEventListFromPayload($candidate);
            if ($events !== []) {
                return $events;
            }
        }

        return [];
    }

    private function extractOpenDeliveryEventListFromPayload(mixed $payload): array
    {
        if (!is_array($payload) || $payload === []) {
            return [];
        }

        if ($this->isSequentialArray($payload) && isset($payload[0]) && is_array($payload[0])) {
            return array_values(array_filter($payload, 'is_array'));
        }

        foreach ($payload as $value) {
            if (!is_array($value)) {
                continue;
            }

            $events = $this->extractOpenDeliveryEventListFromPayload($value);
            if ($events !== []) {
                return $events;
            }
        }

        return [];
    }

    private function normalizeOpenDeliveryEvent(array $event): ?array
    {
        $eventId = $this->normalizeOpenDeliveryString($event['id'] ?? $event['eventId'] ?? $event['event_id'] ?? '');
        $orderId = $this->normalizeOpenDeliveryString($event['orderId'] ?? $event['order_id'] ?? '');
        $eventType = $this->normalizeOpenDeliveryString($event['eventType'] ?? $event['event_type'] ?? $event['type'] ?? '');

        if ($eventId === '' || $orderId === '' || $eventType === '') {
            return null;
        }

        $createdAt = $this->normalizeOpenDeliveryString($event['createdAt'] ?? $event['created_at'] ?? $event['event_time'] ?? '');
        $createdAtTs = $this->normalizeOpenDeliveryTimestamp($createdAt);
        $normalizedCreatedAt = $createdAtTs > 0
            ? date('Y-m-d H:i:s', $createdAtTs)
            : ($createdAt !== '' ? $createdAt : date('Y-m-d H:i:s'));

        return [
            'event_id' => $eventId,
            'order_id' => $orderId,
            'original_event_type' => $eventType,
            'mapped_event_type' => $this->mapOpenDeliveryEventType($eventType),
            'created_at' => $normalizedCreatedAt,
            'created_at_ts' => $createdAtTs,
        ];
    }

    private function normalizeOpenDeliveryTimestamp(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_numeric($value)) {
            $timestamp = (int) $value;
            if ($timestamp > 1000000000000) {
                $timestamp = (int) floor($timestamp / 1000);
            }

            return $timestamp > 0 ? $timestamp : 0;
        }

        try {
            $parsed = new DateTimeImmutable((string) $value);

            return $parsed->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function mapOpenDeliveryEventType(string $eventType): string
    {
        $normalized = strtoupper(trim($eventType));

        return match (true) {
            in_array($normalized, ['CREATED', 'NEW', 'ORDER_CREATED', 'ORDER_NEW'], true) => 'orderNew',
            in_array($normalized, ['CONFIRMED', 'ACCEPTED', 'ORDER_CONFIRMED', 'ORDER_ACCEPTED'], true) => 'orderConfirm',
            in_array($normalized, ['READY_FOR_PICKUP', 'READY', 'ORDER_READY', 'PREPARED', 'PREPARING'], true) => 'orderReady',
            in_array($normalized, ['DISPATCHED', 'PICKED_UP', 'DELIVERING', 'COURIER_TO_STORE', 'IN_TRANSIT', 'ON_THE_WAY'], true) => 'deliveryStatus',
            in_array($normalized, ['DELIVERED', 'CONCLUDED', 'FINISHED', 'COMPLETED', 'CLOSED'], true) => 'orderFinish',
            in_array($normalized, ['CANCELLATION_REQUESTED', 'CANCELLATION_REQUEST', 'ORDER_CANCELLATION_REQUEST', 'ORDER_CANCEL_REQUEST'], true) => 'orderCancelRequest',
            in_array($normalized, ['CANCELLED', 'CANCELED', 'ORDER_CANCELLED', 'ORDER_CANCELED', 'ORDER_CANCEL'], true) => 'orderCancel',
            in_array($normalized, ['CANCELLATION_REQUEST_DENIED', 'CANCELLED_DENIED', 'CANCEL_REQUEST_DENIED', 'CANCEL_DENIED'], true) => 'orderDetailSync',
            default => 'orderDetailSync',
        };
    }

    private function buildOpenDeliveryWebhookPayload(People $provider, array $orderDetails, array $event): array
    {
        $data = is_array($orderDetails['data'] ?? null) ? $orderDetails['data'] : $orderDetails;

        $merchant = $this->findFirstArrayByKeysRecursive($data, ['merchant', 'store', 'shop']) ?? [];
        $customer = $this->findFirstArrayByKeysRecursive($data, ['customer', 'buyer', 'receiver', 'recipient', 'consumer']) ?? [];
        $delivery = $this->findFirstArrayByKeysRecursive($data, ['delivery', 'logistics', 'shipping']) ?? [];

        $items = $this->findFirstValueByKeysRecursive($data, ['items', 'orderItems', 'order_items', 'products']);
        $items = is_array($items) ? $items : [];
        $otherFees = $this->findFirstValueByKeysRecursive($data, ['otherFees', 'other_fees', 'fees']);
        $otherFees = is_array($otherFees) ? $otherFees : [];
        $discounts = $this->findFirstValueByKeysRecursive($data, ['discounts', 'discountList', 'promotion', 'promotions', 'discount']);
        $discounts = is_array($discounts) ? $discounts : [];
        $payments = $this->findFirstValueByKeysRecursive($data, ['payments', 'payment', 'pay']);
        $payments = is_array($payments) ? $payments : [];
        $total = $this->findFirstValueByKeysRecursive($data, ['total', 'totals', 'price', 'summary']);
        $total = is_array($total) ? $total : [];
        $extraInfo = $this->findFirstArrayByKeysRecursive($data, ['extraInfo', 'extra_info', 'extra']) ?? [];

        $orderId = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($orderDetails, ['id', 'orderId', 'order_id']) ?? $event['order_id']);
        $displayId = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($orderDetails, ['displayId', 'display_id', 'orderIndex', 'order_index']) ?? $orderId);
        $merchantId = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($merchant, ['id', 'merchantId', 'merchant_id', 'shopId', 'shop_id']) ?? '');
        $merchantName = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($merchant, ['name', 'merchantName', 'storeName', 'shopName', 'title']) ?? '');
        if ($merchantName === '') {
            $merchantName = 'Loja Food99';
        }

        $customerUid = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($customer, ['uid', 'id', 'customerId', 'customer_id', 'userId', 'user_id']) ?? '');
        $customerNameFallback = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($customer, ['name', 'fullName', 'full_name', 'displayName', 'display_name', 'firstName', 'first_name']) ?? '');
        $customerPhone = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($customer, ['phone', 'phoneNumber', 'mobile', 'mobilePhone', 'contactPhone']) ?? '');

        $addressSource = $this->findFirstArrayByKeysRecursive($delivery, ['address', 'deliveryAddress', 'dropoffAddress', 'recipientAddress', 'receiverAddress'])
            ?? $this->findFirstArrayByKeysRecursive($customer, ['address', 'deliveryAddress', 'dropoffAddress'])
            ?? $this->findFirstArrayByKeysRecursive($data, ['address'])
            ?? [];

        $receiveAddress = [
            'uid' => $customerUid,
            'name' => $customerNameFallback,
            'first_name' => $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($customer, ['first_name', 'firstName']) ?? ''),
            'last_name' => $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($customer, ['last_name', 'lastName']) ?? ''),
            'phone' => $customerPhone,
            'street_name' => $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($addressSource, ['street_name', 'streetName', 'street', 'addressLine1', 'line1', 'address1', 'road']) ?? ''),
            'street_number' => $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($addressSource, ['street_number', 'streetNumber', 'number', 'house_number', 'houseNumber']) ?? ''),
            'district' => $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($addressSource, ['district', 'neighborhood', 'neighbourhood', 'area']) ?? ''),
            'city' => $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($addressSource, ['city', 'city_name', 'municipality']) ?? ''),
            'state' => $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($addressSource, ['state', 'uf', 'province']) ?? ''),
            'country_code' => $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($addressSource, ['country_code', 'countryCode']) ?? 'BR'),
            'postal_code' => $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($addressSource, ['postal_code', 'postalCode', 'zip', 'zip_code']) ?? ''),
            'reference' => $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($addressSource, ['reference', 'reference_point', 'referencePoint']) ?? ''),
            'complement' => $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($addressSource, ['complement', 'complemento']) ?? ''),
            'poi_address' => $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($addressSource, ['poi_address', 'poiAddress', 'address_line', 'addressLine']) ?? ''),
            'poi_lat' => $this->findFirstValueByKeysRecursive($addressSource, ['poi_lat', 'poiLat', 'lat', 'latitude']) ?? 0,
            'poi_lng' => $this->findFirstValueByKeysRecursive($addressSource, ['poi_lng', 'poiLng', 'lng', 'lon', 'longitude']) ?? 0,
        ];

        $peopleService = $this->food99PeopleOperationsService;
        $resolvedCustomerName = $peopleService instanceof Food99PeopleOperationsService
            ? $peopleService->resolveFood99CustomerName(
                $receiveAddress,
                $customerNameFallback !== '' ? $customerNameFallback : 'Cliente Food99',
            )
            : '';
        if (is_string($resolvedCustomerName) && trim($resolvedCustomerName) !== '') {
            $receiveAddress['name'] = trim($resolvedCustomerName);
        } elseif ($receiveAddress['name'] === '') {
            $receiveAddress['name'] = 'Cliente Food99';
        }

        if ($receiveAddress['uid'] === '') {
            $receiveAddress['uid'] = $customerUid !== '' ? $customerUid : $orderId;
        }

        $deliveryType = $this->resolveOpenDeliveryDeliveryType($delivery, $extraInfo, $receiveAddress);
        $fulfillmentMode = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($delivery, ['fulfillmentMode', 'fulfillment_mode', 'mode']) ?? '');
        if ($fulfillmentMode === '') {
            $fulfillmentMode = $deliveryType === '2' ? 'store_delivery' : 'platform_delivery';
        }

        $expectedArrivedEta = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($delivery, ['expected_arrived_eta', 'expectedArrivedEta', 'expected_time', 'expectedTime', 'eta']) ?? '');
        $pickupCode = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($delivery, ['pickup_code', 'pickupCode']) ?? $this->findFirstValueByKeysRecursive($extraInfo, ['pickup_code', 'pickupCode']) ?? '');
        $locator = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($delivery, ['locator']) ?? $this->findFirstValueByKeysRecursive($extraInfo, ['locator']) ?? '');
        $handoverPageUrl = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($delivery, ['handover_page_url', 'handoverPageUrl']) ?? '');
        $virtualPhoneNumber = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($delivery, ['virtual_phone_number', 'virtualPhoneNumber']) ?? '');
        $handoverCode = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($delivery, ['handover_code', 'handoverCode']) ?? $this->findFirstValueByKeysRecursive($extraInfo, ['handover_code', 'handoverCode']) ?? '');
        $riderName = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($delivery, ['rider_name', 'riderName', 'courier_name', 'courierName', 'driver_name', 'driverName']) ?? '');
        $riderPhone = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($delivery, ['rider_phone', 'riderPhone', 'courier_phone', 'courierPhone', 'driver_phone', 'driverPhone']) ?? '');
        $riderToStoreEta = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($delivery, ['rider_to_store_eta', 'riderToStoreEta', 'courier_to_store_eta', 'courierToStoreEta', 'eta_to_store', 'etaToStore']) ?? '');

        $mappedItems = $this->mapOpenDeliveryItems($items);
        $itemsTotalCents = $this->calculateOpenDeliveryItemsTotal($mappedItems);
        $deliveryFeeCents = $this->extractOpenDeliveryMoneyValue($otherFees);
        $discountTotalCents = $this->extractOpenDeliveryMoneyValue($discounts);
        $tipTotalCents = $this->extractOpenDeliveryMoneyValue($this->findFirstValueByKeysRecursive($data, ['tips', 'tip', 'gratitude']) ?? []);
        $customerTotalCents = $this->extractOpenDeliveryMoneyValue($total);

        if ($customerTotalCents <= 0) {
            $customerTotalCents = max(0, $itemsTotalCents + $deliveryFeeCents + $tipTotalCents - $discountTotalCents);
        }

        $orderPriceCents = $customerTotalCents > 0 ? $customerTotalCents : $itemsTotalCents;
        $serviceFeeCents = 0;
        $smallOrderFeeCents = 0;
        $mealTopUpFeeCents = 0;
        $subtotalBeforeDiscountsCents = max(0, $itemsTotalCents + $deliveryFeeCents + $serviceFeeCents + $smallOrderFeeCents + $mealTopUpFeeCents + $tipTotalCents);
        $storeReceivableTotalCents = $customerTotalCents > 0 ? $customerTotalCents : $subtotalBeforeDiscountsCents;
        $weeklySettlementAmountCents = $storeReceivableTotalCents;
        $shopPaidMoneyCents = $storeReceivableTotalCents;

        $paymentEntry = $this->isSequentialArray($payments) ? ($payments[0] ?? []) : $payments;
        $paymentEntry = is_array($paymentEntry) ? $paymentEntry : [];

        $payType = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($paymentEntry, ['payType', 'pay_type', 'type']) ?? $this->findFirstValueByKeysRecursive($data, ['pay_type', 'payType']) ?? '');
        $payMethod = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($paymentEntry, ['payMethod', 'pay_method', 'method']) ?? $this->findFirstValueByKeysRecursive($data, ['pay_method', 'payMethod']) ?? '');
        $payChannel = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($paymentEntry, ['payChannel', 'pay_channel', 'channel']) ?? $this->findFirstValueByKeysRecursive($data, ['pay_channel', 'payChannel']) ?? '');

        $financialService = $this->food99FinancialOperationsService;
        $paymentTypeLabel = $financialService instanceof Food99FinancialOperationsService
            ? $financialService->resolveFood99PaymentTypeLabel($payType, $deliveryType)
            : '';
        $paymentMethodLabel = $financialService instanceof Food99FinancialOperationsService
            ? $financialService->resolveFood99PaymentMethodLabel($payMethod)
            : '';
        $paymentChannelLabel = $financialService instanceof Food99FinancialOperationsService
            ? $financialService->resolveFood99PaymentChannelLabel($payChannel, $payMethod, $deliveryType)
            : '';
        $selectedPaymentLabel = $financialService instanceof Food99FinancialOperationsService
            ? $financialService->resolveFood99SelectedPaymentLabel(
                is_string($paymentChannelLabel) ? $paymentChannelLabel : '',
                is_string($paymentTypeLabel) ? $paymentTypeLabel : '',
                is_string($paymentMethodLabel) ? $paymentMethodLabel : ''
            )
            : '';

        $amountPaidCents = $this->extractOpenDeliveryMoneyValue(
            $this->findFirstValueByKeysRecursive($paymentEntry, [
                'paidAmount',
                'paid_amount',
                'amountPaid',
                'amount_paid',
                'value',
                'amount',
                'total',
                'totalAmount',
                'total_amount',
            ]) ?? $paymentEntry
        );
        if ($amountPaidCents <= 0) {
            $amountPaidCents = $customerTotalCents;
        }

        $amountPendingCents = max(0, $customerTotalCents - $amountPaidCents);
        $changeForCents = $this->extractOpenDeliveryMoneyValue($this->findFirstValueByKeysRecursive($paymentEntry, ['changeFor', 'change_for']) ?? []);
        $changeAmountCents = $this->extractOpenDeliveryMoneyValue($this->findFirstValueByKeysRecursive($paymentEntry, ['changeAmount', 'change_amount']) ?? []);
        $needsChange = $changeForCents > 0 || $changeAmountCents > 0;
        $isPaidOnline = !in_array(strtolower($payMethod), ['2', 'cash', 'offline', 'offline_payment'], true)
            && !in_array(strtolower($payType), ['2', 'cash'], true);
        $shouldConfirmPayment = false;

        $deliveryStatus = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($data, ['delivery_status', 'deliveryStatus', 'status_desc', 'statusDesc', 'status']) ?? '');
        $deliveryStatusLabel = $deliveryStatus;
        $needCutlery = $this->normalizeOpenDeliveryBoolean($this->findFirstValueByKeysRecursive($data, ['need_cutlery', 'needCutlery', 'cutlery', 'cutlery_needed']));
        if ($needCutlery === null) {
            $needCutlery = $this->normalizeOpenDeliveryBoolean($this->findFirstValueByKeysRecursive($customer, ['need_cutlery', 'needCutlery']));
        }

        $addressDisplay = $this->buildOpenDeliveryAddressDisplay($receiveAddress);

        $financialSection = [
            'currency' => 'BRL',
            'items_total' => $itemsTotalCents,
            'delivery_fee' => $deliveryFeeCents,
            'service_fee' => $serviceFeeCents,
            'small_order_fee' => $smallOrderFeeCents,
            'meal_top_up_fee' => $mealTopUpFeeCents,
            'tip_total' => $tipTotalCents,
            'subtotal_before_discounts' => $subtotalBeforeDiscountsCents,
            'discount_total' => $discountTotalCents,
            'store_discount_total' => $discountTotalCents,
            'platform_discount_total' => 0,
            'store_non_delivery_discount_total' => $discountTotalCents,
            'platform_non_delivery_discount_total' => 0,
            'store_delivery_discount_total' => 0,
            'platform_delivery_discount_total' => 0,
            'charge_base_amount' => $customerTotalCents,
            'commission_distribution_amount' => 0,
            'payment_processing_amount' => 0,
            'logistics_cost_amount' => $deliveryFeeCents,
            'platform_charges_amount' => $deliveryFeeCents,
            'weekly_settlement_amount' => $weeklySettlementAmountCents,
            'promotions_total' => $discountTotalCents,
            'items_discount_total' => $discountTotalCents,
            'delivery_discount_total' => 0,
            'coupon_discount_total' => 0,
            'customer_total' => $customerTotalCents,
            'customer_need_paying_money' => $customerTotalCents,
            'store_receivable_total' => $storeReceivableTotalCents,
            'real_pay_total' => $customerTotalCents,
            'refund_total' => 0,
            'store_charged_delivery_price' => $deliveryFeeCents,
            'shop_paid_money' => $shopPaidMoneyCents,
        ];

        $paymentSection = [
            'pay_type' => $payType,
            'pay_type_label' => is_string($paymentTypeLabel) ? $paymentTypeLabel : '',
            'pay_method' => $payMethod,
            'pay_method_label' => is_string($paymentMethodLabel) ? $paymentMethodLabel : '',
            'pay_channel' => $payChannel,
            'pay_channel_label' => is_string($paymentChannelLabel) ? $paymentChannelLabel : '',
            'selected_payment_label' => is_string($selectedPaymentLabel) ? $selectedPaymentLabel : '',
            'amount_paid' => $amountPaidCents,
            'amount_pending' => $amountPendingCents,
            'customer_need_paying_money' => $customerTotalCents,
            'collect_on_delivery_amount' => !$isPaidOnline ? $customerTotalCents : 0,
            'shop_paid_money' => $shopPaidMoneyCents,
            'change_for' => $changeForCents,
            'change_amount' => $changeAmountCents,
            'needs_change' => $needsChange,
            'is_fully_paid' => $amountPendingCents <= 0,
            'should_confirm_payment' => $shouldConfirmPayment,
            'is_paid_online' => $isPaidOnline,
            'delivery_99_always_paid_rule' => $deliveryType === '1',
        ];

        $customerSection = [
            'name' => $receiveAddress['name'] !== '' ? $receiveAddress['name'] : 'Cliente Food99',
            'phone' => $customerPhone,
            'uid' => $receiveAddress['uid'],
        ];

        $normalizedDelivery = $delivery;
        $normalizedDelivery['delivery_type'] = $deliveryType;
        $normalizedDelivery['fulfillment_mode'] = $fulfillmentMode;
        $normalizedDelivery['expected_arrived_eta'] = $expectedArrivedEta;
        $normalizedDelivery['pickup_code'] = $pickupCode;
        $normalizedDelivery['locator'] = $locator;
        $normalizedDelivery['handover_page_url'] = $handoverPageUrl;
        $normalizedDelivery['virtual_phone_number'] = $virtualPhoneNumber;
        $normalizedDelivery['handover_code'] = $handoverCode;
        $normalizedDelivery['rider_name'] = $riderName;
        $normalizedDelivery['rider_phone'] = $riderPhone;
        $normalizedDelivery['rider_to_store_eta'] = $riderToStoreEta;
        $normalizedDelivery['delivery_status'] = $deliveryStatus;
        $normalizedDelivery['status_desc'] = $deliveryStatusLabel;

        $notesSection = [
            'remark' => $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($data, ['remark', 'note', 'notes', 'observation']) ?? ''),
            'need_cutlery' => $needCutlery,
        ];

        $identifiersSection = [
            'remote_order_id' => $orderId,
            'order_index' => $displayId,
            'delivery_type' => $deliveryType,
            'pickup_code' => $pickupCode,
            'handover_code' => $handoverCode,
        ];

        $priceSection = [
            'order_price' => $orderPriceCents,
            'delivery_fee' => $deliveryFeeCents,
            'service_fee' => $serviceFeeCents,
            'small_order_fee' => $smallOrderFeeCents,
            'meal_top_up_fee' => $mealTopUpFeeCents,
            'tip_total' => $tipTotalCents,
            'discount_total' => $discountTotalCents,
            'customer_total' => $customerTotalCents,
            'customer_need_paying_money' => $customerTotalCents,
            'store_receivable_total' => $storeReceivableTotalCents,
            'weekly_settlement_amount' => $weeklySettlementAmountCents,
            'shop_paid_money' => $shopPaidMoneyCents,
        ];

        $mappedItems = $this->mapOpenDeliveryItems($items);
        $eventTime = $event['created_at'] ?? date('Y-m-d H:i:s');
        $mappedEventType = (string) ($event['mapped_event_type'] ?? 'orderDetailSync');
        $originalEventType = (string) ($event['original_event_type'] ?? $mappedEventType);

        return [
            'type' => $mappedEventType,
            'event_time' => $eventTime,
            'event_id' => $event['event_id'] ?? null,
            'latest_event_type' => $mappedEventType,
            'latest_event_at' => $eventTime,
            '__webhook' => [
                'event_id' => $event['event_id'] ?? null,
                'event_type' => $originalEventType,
                'received_at' => date('Y-m-d H:i:s'),
                'shop_id' => (string) $provider->getId(),
                'order_id' => $orderId,
            ],
            'app_shop_id' => (string) $provider->getId(),
            'data' => [
                'order_id' => $orderId,
                'order_info' => [
                    'order_id' => $orderId,
                    'order_index' => $displayId,
                    'shop' => [
                        'shop_id' => (string) $provider->getId(),
                        'shop_name' => $merchantName,
                        'merchant_id' => $merchantId !== '' ? $merchantId : null,
                        'merchant_name' => $merchantName,
                    ],
                    'merchant' => $merchant,
                    'order_items' => $mappedItems,
                    'receive_address' => $receiveAddress,
                    'price' => $priceSection,
                    'delivery_type' => $deliveryType,
                    'pay_type' => $payType,
                    'pay_method' => $payMethod,
                    'pay_channel' => $payChannel,
                    'remark' => $notesSection['remark'],
                    'delivery_status' => $deliveryStatus,
                    'status_desc' => $deliveryStatusLabel,
                    'customer' => $customerSection,
                    'payment' => $paymentSection,
                    'delivery' => $normalizedDelivery,
                ],
                'shop' => [
                    'shop_id' => (string) $provider->getId(),
                    'shop_name' => $merchantName,
                    'merchant_id' => $merchantId !== '' ? $merchantId : null,
                    'merchant_name' => $merchantName,
                ],
                'order_items' => $mappedItems,
                'receive_address' => $receiveAddress,
                'price' => $priceSection,
                'delivery_type' => $deliveryType,
                'pay_type' => $payType,
                'pay_method' => $payMethod,
                'pay_channel' => $payChannel,
                'delivery_status' => $deliveryStatus,
                'status_desc' => $deliveryStatusLabel,
                'remark' => $notesSection['remark'],
                'customer' => $customerSection,
                'payment' => $paymentSection,
                'delivery' => $normalizedDelivery,
            ],
            'financial' => $financialSection,
            'payment' => $paymentSection,
            'customer' => $customerSection,
            'address' => array_merge($receiveAddress, [
                'display' => $addressDisplay,
            ]),
            'notes' => $notesSection,
            'identifiers' => $identifiersSection,
        ];
    }

    private function findFirstValueByKeysRecursive(mixed $payload, array $keys): mixed
    {
        if (!is_array($payload)) {
            return null;
        }

        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            if (is_array($value)) {
                return $value;
            }

            if ($value !== null && trim((string) $value) !== '') {
                return $value;
            }
        }

        foreach ($payload as $value) {
            if (!is_array($value)) {
                continue;
            }

            $resolved = $this->findFirstValueByKeysRecursive($value, $keys);
            if ($resolved !== null && $resolved !== []) {
                return $resolved;
            }
        }

        return null;
    }

    private function findFirstArrayByKeysRecursive(mixed $payload, array $keys): ?array
    {
        $value = $this->findFirstValueByKeysRecursive($payload, $keys);

        return is_array($value) ? $value : null;
    }

    private function findFirstScalarByKeysRecursive(mixed $payload, array $keys): ?string
    {
        $value = $this->findFirstValueByKeysRecursive($payload, $keys);
        if (is_array($value) || $value === null) {
            return null;
        }

        $normalized = $this->normalizeOpenDeliveryString($value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeOpenDeliveryString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return '';
    }

    private function normalizeOpenDeliveryMoneyValue(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value * 100);
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return 0;
        }

        $clean = preg_replace('/[^\d,.\-]/', '', $normalized) ?: '';
        if ($clean === '') {
            return 0;
        }

        if (str_contains($clean, '.') || str_contains($clean, ',')) {
            $clean = str_replace(',', '.', $clean);

            return (int) round(((float) $clean) * 100);
        }

        return (int) $clean;
    }

    private function extractOpenDeliveryMoneyValue(mixed $section): int
    {
        if ($section === null || $section === '' || $section === []) {
            return 0;
        }

        if (!is_array($section)) {
            return $this->normalizeOpenDeliveryMoneyValue($section);
        }

        if ($this->isSequentialArray($section)) {
            $sum = 0;
            foreach ($section as $entry) {
                if (is_array($entry)) {
                    $sum += $this->extractOpenDeliveryMoneyValue($entry);
                    continue;
                }

                $sum += $this->normalizeOpenDeliveryMoneyValue($entry);
            }

            return $sum;
        }

        $candidate = $this->findFirstValueByKeysRecursive($section, ['value', 'amount', 'total', 'price', 'money', 'fee']);
        if ($candidate !== null && $candidate !== []) {
            return $this->extractOpenDeliveryMoneyValue($candidate);
        }

        $sum = 0;
        foreach ($section as $value) {
            if (is_array($value)) {
                $sum += $this->extractOpenDeliveryMoneyValue($value);
            }
        }

        return $sum;
    }

    private function normalizeOpenDeliveryQuantity(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 1.0;
        }

        if (is_numeric($value)) {
            $quantity = (float) $value;

            return $quantity > 0 ? $quantity : 1.0;
        }

        return 1.0;
    }

    private function isSequentialArray(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function extractOpenDeliveryNestedItems(array $item): array
    {
        foreach ([
            'sub_item_list',
            'subItems',
            'sub_items',
            'items',
            'modifierItems',
            'modifiers',
            'options',
            'children',
            'childItems',
        ] as $key) {
            $candidate = $item[$key] ?? null;
            if (!is_array($candidate) || $candidate === []) {
                continue;
            }

            if ($this->isSequentialArray($candidate)) {
                return array_values(array_filter($candidate, 'is_array'));
            }

            if (isset($candidate[0]) && is_array($candidate[0])) {
                return array_values(array_filter($candidate, 'is_array'));
            }
        }

        return [];
    }

    private function mapOpenDeliveryItems(array $items, string $groupName = ''): array
    {
        $mappedItems = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $mappedItems[] = $this->mapOpenDeliveryItem($item, $groupName);
        }

        return $mappedItems;
    }

    private function mapOpenDeliveryItem(array $item, string $groupName = ''): array
    {
        $name = $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($item, ['name', 'title', 'productName', 'product_name']) ?? '');
        if ($name === '') {
            $name = 'Produto Food99';
        }

        $quantity = $this->normalizeOpenDeliveryQuantity($this->findFirstValueByKeysRecursive($item, ['amount', 'quantity', 'qty', 'count']) ?? 1);
        $unitPrice = $this->extractOpenDeliveryMoneyValue($this->findFirstValueByKeysRecursive($item, ['sku_price', 'price', 'unitPrice', 'unit_price', 'value']) ?? 0);
        if ($unitPrice <= 0) {
            $totalPrice = $this->extractOpenDeliveryMoneyValue($this->findFirstValueByKeysRecursive($item, ['totalPrice', 'total_price', 'subtotal']) ?? 0);
            if ($totalPrice > 0 && $quantity > 0) {
                $unitPrice = (int) round($totalPrice / $quantity);
            }
        }

        $mappedItem = [
            'id' => $this->resolveOpenDeliveryItemCode($item, 'item'),
            'app_item_id' => $this->resolveOpenDeliveryItemCode($item, 'item'),
            'mdu_id' => $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($item, ['mdu_id', 'mduId', 'skuId', 'sku_id', 'productId', 'product_id', 'id']) ?? ''),
            'app_external_id' => $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($item, ['app_external_id', 'appExternalId', 'externalId', 'external_id']) ?? ''),
            'name' => $name,
            'amount' => $quantity,
            'sku_price' => $unitPrice,
            'content_name' => $groupName !== '' ? $groupName : $name,
            'app_content_id' => $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($item, ['app_content_id', 'appContentId', 'contentId', 'content_id', 'groupId', 'group_id', 'modifierGroupId', 'modifier_group_id']) ?? ''),
            'remark' => $this->normalizeOpenDeliveryString($this->findFirstValueByKeysRecursive($item, ['remark', 'remarks', 'note', 'notes', 'comment', 'comments', 'observation']) ?? ''),
        ];

        $nestedItems = $this->extractOpenDeliveryNestedItems($item);
        if ($nestedItems !== []) {
            $mappedItem['sub_item_list'] = $this->mapOpenDeliveryItems($nestedItems, $name);
        }

        return $mappedItem;
    }

    private function resolveOpenDeliveryItemCode(array $item, string $fallbackPrefix): string
    {
        foreach (['app_item_id', 'mdu_id', 'app_external_id', 'id', 'skuId', 'sku_id', 'productId', 'product_id', 'externalId', 'external_id'] as $key) {
            $candidate = $this->normalizeOpenDeliveryString($item[$key] ?? '');
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $fallbackSource = implode('|', array_filter([
            $fallbackPrefix,
            $this->normalizeOpenDeliveryString($item['name'] ?? ''),
            $this->normalizeOpenDeliveryString($item['content_name'] ?? ''),
            $this->normalizeOpenDeliveryString($item['app_content_id'] ?? ''),
            $this->normalizeOpenDeliveryString($item['sku_price'] ?? ''),
        ]));

        return 'open-delivery:' . substr(sha1($fallbackSource !== '' ? $fallbackSource : json_encode($item)), 0, 24);
    }

    private function calculateOpenDeliveryItemsTotal(array $items): int
    {
        $total = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $quantity = $this->normalizeOpenDeliveryQuantity($item['amount'] ?? 1);
            $unitPrice = $this->normalizeOpenDeliveryMoneyValue($item['sku_price'] ?? 0);
            $total += (int) round($quantity * $unitPrice);

            if (!empty($item['sub_item_list']) && is_array($item['sub_item_list'])) {
                $total += $this->calculateOpenDeliveryItemsTotal($item['sub_item_list']);
            }
        }

        return $total;
    }

    private function buildOpenDeliveryAddressDisplay(array $address): ?string
    {
        $parts = array_filter([
            $this->normalizeOpenDeliveryString($address['poi_address'] ?? ''),
            $this->normalizeOpenDeliveryString($address['street_name'] ?? ''),
            $this->normalizeOpenDeliveryString($address['street_number'] ?? ''),
            $this->normalizeOpenDeliveryString($address['district'] ?? ''),
            $this->normalizeOpenDeliveryString($address['city'] ?? ''),
            $this->normalizeOpenDeliveryString($address['state'] ?? ''),
            $this->normalizeOpenDeliveryString($address['postal_code'] ?? ''),
            $this->normalizeOpenDeliveryString($address['reference'] ?? ''),
        ], static fn (string $part): bool => $part !== '');

        if ($parts === []) {
            return null;
        }

        return implode(', ', array_values(array_unique($parts)));
    }

    private function normalizeOpenDeliveryBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'y', 'sim', 's'], true);
    }

    private function resolveOpenDeliveryDeliveryType(array $delivery, array $extraInfo, array $address): string
    {
        $candidate = strtolower(trim((string) ($this->findFirstScalarByKeysRecursive($delivery, ['deliveredBy', 'deliveryType', 'type', 'fulfillmentMode', 'fulfillment_mode']) ?? '')));
        if (in_array($candidate, ['1', 'platform', 'platform_delivery', 'delivery', '99', '99food', '99food_delivery'], true)) {
            return '1';
        }

        if (in_array($candidate, ['2', 'store', 'shop', 'merchant', 'self', 'self_delivery', 'store_delivery'], true)) {
            return '2';
        }

        if ($this->normalizeOpenDeliveryString($this->findFirstScalarByKeysRecursive($delivery, ['pickupCode', 'pickup_code', 'handoverCode', 'handover_code', 'locator', 'handoverPageUrl', 'handover_page_url']) ?? '') !== '') {
            return '2';
        }

        $riderName = $this->normalizeOpenDeliveryString($this->findFirstScalarByKeysRecursive($delivery, ['riderName', 'rider_name', 'courierName', 'courier_name', 'driverName', 'driver_name']) ?? '');
        $riderPhone = $this->normalizeOpenDeliveryString($this->findFirstScalarByKeysRecursive($delivery, ['riderPhone', 'rider_phone', 'courierPhone', 'courier_phone', 'driverPhone', 'driver_phone']) ?? '');
        if ($riderName !== '' || $riderPhone !== '') {
            return '1';
        }

        $deliveryMode = strtolower(trim((string) ($this->findFirstScalarByKeysRecursive($extraInfo, ['deliveryMode', 'delivery_mode', 'fulfillmentMode', 'fulfillment_mode']) ?? '')));
        if (in_array($deliveryMode, ['pickup', 'self', 'store', 'merchant'], true)) {
            return '2';
        }

        return '1';
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

    public function resolveIncomingProductGroup(
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

    public function discoveryClient(array $address, array $payload = [], ?People $provider = null): ?People
    {
        $peopleService = $this->food99PeopleOperationsService;
        $remoteClientId = is_object($peopleService) && method_exists($peopleService, 'resolveFood99RemoteClientId')
            ? (string) $peopleService->resolveFood99RemoteClientId($address, $payload)
            : $this->resolveFood99RemoteClientId($address, $payload);

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

    public function isReadyQueueTransition(OrderProductQueue $oldQueue, OrderProductQueue $newQueue): bool
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

    public function areAllOrderProductQueuesReady(Order $order): bool
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
