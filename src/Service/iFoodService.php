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
use ControleOnline\Entity\ExtraData;
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
use ControleOnline\Service\Client\WebsocketClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use ControleOnline\Service\LoggerService;
use DateTime;
use ControleOnline\Event\EntityChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class iFoodService extends DefaultFoodService implements EventSubscriberInterface
{
    private const APP_CONTEXT = Order::APP_IFOOD;
    private const API_BASE_URL = 'https://merchant-api.ifood.com.br';
    private const SELF_DELIVERY_CONFIRMATION_URL = 'https://confirmacao-entrega-propria.ifood.com.br/';
    private const MAX_IMAGE_UPLOAD_BYTES = 5242880; // 5MB
    private const IMAGE_UPLOAD_PAYLOAD_MARGIN_BYTES = 512;
    private const IMAGE_UPLOAD_MAX_DIMENSION = 3000;
    private const CATALOG_CONCURRENT_RETRY_DELAYS_US = [500000, 1500000, 3000000, 5000000];
    private static array $authTokenCache = [];
    private static array $catalogImagePathCache = [];
    // INICIALIZAÇÃO
    // Define constantes: app name, logger e entidade padrão do iFood
    private function init()
    {
        self::$app = 'iFood';
        self::$logger = $this->loggerService->getLogger(self::$app);
        self::$foodPeople = $this->peopleService->discoveryPeople('14380200000121', null, null, 'Ifood.com Agência de Restaurantes Online S.A', 'J');
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

    private function normalizeString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
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

    private function normalizeDigits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    private function resolveCustomerDocumentNumber(array $customerData): ?string
    {
        $digits = $this->normalizeDigits($this->normalizeString(
            $customerData['documentNumber']
                ?? $customerData['document_number']
                ?? null
        ));
        if ($digits === '') {
            return null;
        }

        $length = strlen($digits);
        if ($length !== 11 && $length !== 14) {
            return null;
        }

        return $digits;
    }

    private function resolveCustomerDocumentType(array $customerData, ?string $documentNumber = null): ?string
    {
        $documentType = strtoupper($this->normalizeString(
            $customerData['documentType']
                ?? $customerData['document_type']
                ?? null
        ));
        if ($documentType !== '') {
            return $documentType;
        }

        if ($documentNumber === null || $documentNumber === '') {
            return null;
        }

        return strlen($documentNumber) > 11 ? 'CNPJ' : 'CPF';
    }

    private function resolveBooleanFlag(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower($this->normalizeString($value));
        if ($normalized === '') {
            return null;
        }

        $parsed = match ($normalized) {
            '1', 'true', 'yes', 'y', 'sim' => true,
            '0', 'false', 'no', 'n', 'nao', 'não' => false,
            default => null,
        };

        if ($parsed !== null) {
            return $parsed;
        }

        return null;
    }

    private function resolveTaxDocumentRequested(array $customerData, ?string $documentNumber = null): bool
    {
        $explicitFlag = $this->resolveBooleanFlag(
            $customerData['taxDocumentRequested']
                ?? $customerData['tax_document_requested']
                ?? $customerData['requiresTaxDocument']
                ?? $customerData['requires_tax_document']
                ?? $customerData['issueTaxDocument']
                ?? $customerData['issue_tax_document']
                ?? null
        );

        if ($explicitFlag !== null) {
            return $explicitFlag;
        }

        return !empty($documentNumber);
    }

    private function resolveCustomerPhoneForDiscovery(array $customerData): ?array
    {
        $phoneData = is_array($customerData['phone'] ?? null) ? $customerData['phone'] : [];
        $rawNumber = $this->normalizeString($phoneData['number'] ?? null);
        $localizer = $this->normalizeString($phoneData['localizer'] ?? null);
        $digits = $this->normalizeDigits($rawNumber);

        if ($digits === '') {
            return null;
        }

        // O iFood costuma fornecer um telefone operacional mascarado (0800 + localizer).
        // Esse numero nao deve ser usado para identificar/reaproveitar o cliente local.
        if ($localizer !== '' || str_starts_with($digits, '0800')) {
            return null;
        }

        $ddi = '55';
        if (str_starts_with($digits, '55') && (strlen($digits) === 12 || strlen($digits) === 13)) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) !== 10 && strlen($digits) !== 11) {
            return null;
        }

        $ddd = substr($digits, 0, 2);
        $phone = substr($digits, 2);
        if ($ddd === '' || $phone === '') {
            return null;
        }

        return [
            'ddi' => $ddi,
            'ddd' => $ddd,
            'phone' => $phone,
        ];
    }

    private function shouldUpdateIfoodClientName(People $client, string $resolvedName): bool
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
            || $currentName === 'cliente ifood'
            || str_starts_with($currentName, 'cliente ifood ');
    }

    private function syncIfoodClientData(
        People $client,
        People $provider,
        string $resolvedName,
        ?array $phone,
        ?string $document = null,
        ?string $documentType = null,
        string $remoteClientId = ''
    ): People {
        if ($this->shouldUpdateIfoodClientName($client, $resolvedName)) {
            $client->setName($resolvedName);
            $this->entityManager->persist($client);
        }

        if (!empty($phone)) {
            try {
                $this->peopleService->addPhone($client, $phone);
            } catch (\Throwable $exception) {
                self::$logger->warning('iFood client phone could not be synced', [
                    'client_id' => $client->getId(),
                    'provider_id' => $provider->getId(),
                    'phone' => $phone,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (!empty($document)) {
            try {
                $existingDocument = $this->peopleService->getDocument($document, $documentType);
                if ($existingDocument && $existingDocument->getPeople()->getId() !== $client->getId()) {
                    self::$logger->warning('iFood client document already belongs to another people record', [
                        'client_id' => $client->getId(),
                        'provider_id' => $provider->getId(),
                        'document' => $document,
                        'document_type' => $documentType,
                        'document_people_id' => $existingDocument->getPeople()->getId(),
                    ]);
                } else {
                    $this->peopleService->addDocument($client, $document, $documentType);
                }
            } catch (\Throwable $exception) {
                self::$logger->warning('iFood client document could not be synced', [
                    'client_id' => $client->getId(),
                    'provider_id' => $provider->getId(),
                    'document' => $document,
                    'document_type' => $documentType,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($remoteClientId !== '') {
            $this->bindIfoodCodeToPeople($client, $remoteClientId);
        }

        $this->peopleService->discoveryLink($provider, $client, 'client');

        return $client;
    }

    private function bindIfoodCodeToPeople(People $people, string $code, string $fieldName = 'code'): People
    {
        $code = $this->normalizeString($code);
        if ($code === '') {
            return $people;
        }

        $currentBinding = $this->findEntityByExtraData('People', $fieldName, $code, People::class);
        if ($currentBinding instanceof People && $currentBinding->getId() === $people->getId()) {
            return $people;
        }

        $extraFields = $this->extraDataService->discoveryExtraFields($fieldName, self::APP_CONTEXT, '{}');
        $extraData = new ExtraData();
        $extraData->setEntityId((string) $people->getId());
        $extraData->setEntityName('People');
        $extraData->setExtraFields($extraFields);
        $extraData->setValue($code);

        $this->entityManager->persist($extraData);
        $this->entityManager->flush();

        if ($currentBinding instanceof People && $currentBinding->getId() !== $people->getId()) {
            self::$logger->warning('iFood client code rebound to a different local people record', [
                'ifood_customer_id' => $code,
                'previous_people_id' => $currentBinding->getId(),
                'current_people_id' => $people->getId(),
            ]);
        }

        return $people;
    }

    private function findEntityByExtraData(string $entityName, string $fieldName, string $value, string $entityClass): ?object
    {
        if ($value === '') {
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
            'context'    => self::APP_CONTEXT,
            'fieldName'  => $fieldName,
            'entityName' => $entityName,
            'value'      => $value,
        ]);

        if (!is_numeric($entityId)) {
            return null;
        }

        return $this->entityManager->getRepository($entityClass)->find((int) $entityId);
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
        $storedOrderDetails = $order instanceof Order ? $this->findStoredIfoodOrderDetails($order) : [];
        $fetchedOrderDetails = [];
        $orderDetails = [];

        if ($eventOrderDetails !== []) {
            $orderDetails = $storedOrderDetails !== []
                ? $this->mergeIfoodOrderDetails($storedOrderDetails, $eventOrderDetails)
                : $eventOrderDetails;
        } elseif ($storedOrderDetails !== []) {
            $orderDetails = $storedOrderDetails;
        } elseif (!$order instanceof Order) {
            $fetchedOrderDetails = $this->fetchOrderDetails($orderId);
            if (is_array($fetchedOrderDetails)) {
                $orderDetails = $fetchedOrderDetails;
            }
        }

        if ($order instanceof Order && $orderDetails !== []) {
            $this->persistResolvedIfoodOrderDetails($order, $event, $orderDetails);
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

    private function findStoredIfoodOrderDetails(Order $order): array
    {
        $context = $this->getIfoodContextOtherInformations($order);
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

    private function persistResolvedIfoodOrderDetails(Order $order, array $event, array $orderDetails): void
    {
        $otherInformations = $this->getDecodedOrderOtherInformations($order);
        if ($otherInformations === []) {
            $otherInformations = [];
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

    private function resolveIfoodInvoicePaymentTypeData(array $payment): array
    {
        $method = strtoupper($this->normalizeString($payment['method'] ?? null));
        $type = strtoupper($this->normalizeString($payment['type'] ?? null));
        $brand = strtoupper($this->normalizeString($payment['card']['brand'] ?? null));
        $walletName = $this->normalizeString($payment['wallet']['name'] ?? null);
        $liability = strtoupper($this->normalizeString($payment['liability'] ?? null));
        $selectedPaymentLabel = trim($method . ($brand !== '' ? " ({$brand})" : ''));

        $paymentTypeData = match ($method) {
            'PIX' => ['paymentType' => 'PIX', 'aliases' => []],
            'CASH' => ['paymentType' => 'Dinheiro', 'aliases' => []],
            'DEBIT' => ['paymentType' => 'Debito', 'aliases' => ['Cartao de Debito', 'Cartão de Débito', 'Débito']],
            'CREDIT' => ['paymentType' => 'Credito', 'aliases' => ['Cartao de Credito', 'Cartão de Crédito', 'Crédito']],
            'MEAL_VOUCHER' => ['paymentType' => 'Refeicao', 'aliases' => ['Vale Refeicao', 'Vale Refeição', 'Refeição']],
            'FOOD_VOUCHER' => ['paymentType' => 'Alimentacao', 'aliases' => ['Vale Alimentacao', 'Vale Alimentação', 'Alimentação']],
            'DIGITAL_WALLET' => ['paymentType' => $walletName !== '' ? $walletName : 'Carteira Digital', 'aliases' => ['Digital Wallet']],
            'GIFT_CARD' => ['paymentType' => 'Gift Card', 'aliases' => []],
            'OTHER' => ['paymentType' => $selectedPaymentLabel !== '' ? $selectedPaymentLabel : 'iFood', 'aliases' => []],
            default => ['paymentType' => $selectedPaymentLabel !== '' ? $selectedPaymentLabel : 'iFood', 'aliases' => []],
        };

        $paymentTypeData['frequency'] = 'single';
        $paymentTypeData['installments'] = 'single';
        $paymentTypeData['paymentCode'] = $brand !== '' ? $brand : ($method !== '' ? $method : null);
        $paymentTypeData['pay_type'] = strtolower($type);
        $paymentTypeData['pay_method'] = strtolower($method);
        $paymentTypeData['pay_channel'] = $brand !== '' ? $brand : $method;
        $paymentTypeData['selected_payment_label'] = $selectedPaymentLabel;
        $paymentTypeData['payment_liability'] = $liability;
        $paymentTypeData['payment_wallet_name'] = $walletName;

        return $paymentTypeData;
    }

    private function resolveIfoodProviderPaymentType(People $provider, array $paymentTypeData, ?Wallet $wallet = null): PaymentType
    {
        $candidateNames = array_values(array_unique(array_filter(array_merge(
            [(string) ($paymentTypeData['paymentType'] ?? '')],
            is_array($paymentTypeData['aliases'] ?? null) ? $paymentTypeData['aliases'] : []
        ))));

        foreach ($candidateNames as $candidateName) {
            $paymentType = $this->entityManager->getRepository(PaymentType::class)->findOneBy([
                'people' => $provider,
                'paymentType' => $candidateName,
            ]);

            if (!$paymentType instanceof PaymentType) {
                continue;
            }

            if ($wallet instanceof Wallet) {
                $this->ensureIfoodWalletPaymentType(
                    $wallet,
                    $paymentType,
                    $paymentTypeData['paymentCode'] ?? null
                );
            }

            return $paymentType;
        }

        $paymentType = $this->walletService->discoverPaymentType($provider, [
            'paymentType' => $candidateNames[0] ?? 'iFood',
            'frequency' => $paymentTypeData['frequency'] ?? 'single',
            'installments' => $paymentTypeData['installments'] ?? 'single',
        ]);

        if ($wallet instanceof Wallet) {
            $this->ensureIfoodWalletPaymentType(
                $wallet,
                $paymentType,
                $paymentTypeData['paymentCode'] ?? null
            );
        }

        return $paymentType;
    }

    private function resolveIfoodSettlementPaymentType(People $provider, ?Wallet $wallet = null): PaymentType
    {
        return $this->resolveIfoodProviderPaymentType($provider, [
            'paymentType' => 'iFood',
            'aliases' => ['IFOOD'],
            'frequency' => 'single',
            'installments' => 'single',
            'paymentCode' => self::APP_CONTEXT,
        ], $wallet);
    }

    private function ensureIfoodWalletPaymentType(
        Wallet $wallet,
        PaymentType $paymentType,
        $paymentCode = null
    ): WalletPaymentType {
        $normalizedPaymentCode = $this->normalizeString($paymentCode);

        $walletPaymentType = $this->entityManager
            ->getRepository(WalletPaymentType::class)
            ->findOneBy([
                'wallet' => $wallet,
                'paymentType' => $paymentType,
            ]);

        if ($walletPaymentType instanceof WalletPaymentType) {
            $currentPaymentCode = $this->normalizeString($walletPaymentType->getPaymentCode());
            if ($currentPaymentCode === '' && $normalizedPaymentCode !== '') {
                $walletPaymentType->setPaymentCode($normalizedPaymentCode);
                $this->entityManager->persist($walletPaymentType);
                $this->entityManager->flush();
            }

            return $walletPaymentType;
        }

        $walletPaymentType = new WalletPaymentType();
        $walletPaymentType->setWallet($wallet);
        $walletPaymentType->setPaymentType($paymentType);
        $walletPaymentType->setPaymentCode($normalizedPaymentCode !== '' ? $normalizedPaymentCode : null);
        $this->entityManager->persist($walletPaymentType);
        $this->entityManager->flush();

        return $walletPaymentType;
    }

    private function shouldIfoodUseMarketplaceWalletForReceivable(array $paymentTypeData, bool $isPrepaid): bool
    {
        $paymentLiability = strtoupper($this->normalizeString($paymentTypeData['payment_liability'] ?? null));
        $paymentType = strtoupper($this->normalizeString($paymentTypeData['pay_type'] ?? null));

        return $isPrepaid
            || $paymentLiability === 'IFOOD'
            || $paymentType === 'ONLINE';
    }

    private function resolveIfoodReceivableWallet(
        Order $order,
        PaymentType $paymentType,
        array $paymentTypeData,
        bool $isPrepaid
    ): Wallet {
        $walletName = $this->shouldIfoodUseMarketplaceWalletForReceivable($paymentTypeData, $isPrepaid)
            ? self::$app
            : $this->normalizeString($paymentType->getPaymentType());

        if ($walletName === '') {
            $walletName = self::$app;
        }

        return $this->walletService->discoverWallet($order->getProvider(), $walletName);
    }

    private function applyIfoodInvoiceContract(
        Invoice $invoice,
        PaymentType $paymentType,
        array $metadata,
        ?Status $status = null,
        ?Wallet $sourceWallet = null,
        ?Wallet $destinationWallet = null
    ): void {
        if ($status instanceof Status) {
            $invoice->setStatus($status);
        }

        if ($sourceWallet instanceof Wallet || $invoice->getSourceWallet() !== $sourceWallet) {
            $invoice->setSourceWallet($sourceWallet);
        }

        if ($destinationWallet instanceof Wallet || $invoice->getDestinationWallet() !== $destinationWallet) {
            $invoice->setDestinationWallet($destinationWallet);
        }

        $invoice->setPaymentType($paymentType);

        $otherInformations = $invoice->getOtherInformations(true);
        $serializedInformations = $otherInformations instanceof \stdClass
            ? (array) $otherInformations
            : (is_array($otherInformations) ? $otherInformations : []);
        $currentIfoodData = $serializedInformations[self::APP_CONTEXT] ?? [];

        if ($currentIfoodData instanceof \stdClass) {
            $currentIfoodData = (array) $currentIfoodData;
        }

        $serializedInformations[self::APP_CONTEXT] = array_merge(
            is_array($currentIfoodData) ? $currentIfoodData : [],
            $metadata
        );

        $invoice->setOtherInformations($serializedInformations);

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();
    }

    private function createIfoodPayableInvoice(
        Order $order,
        PaymentType $paymentType,
        float $amount,
        Status $status,
        Wallet $providerWallet,
        Wallet $ifoodWallet,
        string $purpose,
        array $metadata = []
    ): ?Invoice {
        $normalizedAmount = round($amount, 2);
        if ($normalizedAmount <= 0) {
            return null;
        }

        $invoice = $this->invoiceService->createInvoice(
            $order,
            $order->getProvider(),
            self::$foodPeople,
            $normalizedAmount,
            $status,
            new DateTime(),
            $providerWallet,
            $ifoodWallet
        );

        $this->applyIfoodInvoiceContract(
            $invoice,
            $paymentType,
            array_merge([
                'financial_kind' => 'account_payable',
                'invoice_purpose' => $purpose,
                'marketplace' => self::APP_CONTEXT,
            ], $metadata),
            $status,
            $providerWallet,
            $ifoodWallet
        );

        return $invoice;
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

    private function ensureIfoodFieldId(string $fieldName, string $fieldType = 'text'): ?int
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
        try {
            $fieldId = $connection->fetchOne($sql, [
                'context' => self::APP_CONTEXT,
                'fieldName' => $fieldName,
            ]);
        } catch (\Throwable $e) {
            self::$logger->error('iFood extra field lookup failed', [
                'field_name' => $fieldName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

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
            self::$logger->warning('iFood extra field creation failed, retrying lookup', [
                'field_name' => $fieldName,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $fieldId = $connection->fetchOne($sql, [
                'context' => self::APP_CONTEXT,
                'fieldName' => $fieldName,
            ]);
        } catch (\Throwable $e) {
            self::$logger->error('iFood extra field lookup retry failed', [
                'field_name' => $fieldName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return is_numeric($fieldId) ? (int) $fieldId : null;
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

        $fieldId = $this->ensureIfoodFieldId($fieldName, $fieldType);
        if (!$fieldId) {
            self::$logger->error('iFood extra field could not be ensured', [
                'entity_name' => $entityName,
                'entity_id' => $entityId,
                'field_name' => $fieldName,
            ]);
            return null;
        }

        $normalizedValue = $this->normalizeExtraDataValue($value);
        $connection = $this->entityManager->getConnection();
        try {
            $existingId = $connection->fetchOne(
                'SELECT id FROM extra_data WHERE extra_fields_id = :fieldId AND LOWER(entity_name) = LOWER(:entityName) AND entity_id = :entityId ORDER BY id DESC LIMIT 1',
                [
                    'fieldId' => $fieldId,
                    'entityName' => $entityName,
                    'entityId' => $entityId,
                ]
            );
        } catch (\Throwable $e) {
            self::$logger->error('iFood extra data lookup failed', [
                'entity_name' => $entityName,
                'entity_id' => $entityId,
                'field_name' => $fieldName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

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
            self::$logger->error('iFood extra data upsert failed', [
                'entity_name' => $entityName,
                'entity_id' => $entityId,
                'field_name' => $fieldName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return $normalizedValue === '' ? null : $normalizedValue;
    }

    private function persistOrderIntegrationState(Order $order, array $fields): void
    {
        foreach ($fields as $fieldName => $value) {
            $this->upsertIfoodExtraDataValue('Order', (int) $order->getId(), (string) $fieldName, $value);
        }
    }

    private function getIfoodExtraDataValue(string $entityName, int $entityId, string $fieldName = 'code'): ?string
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

    public function getStoredOrderIntegrationState(Order $order): array
    {
        $this->init();

        $orderId = (int) $order->getId();
        $state = [
            'ifood_id' => $this->getIfoodExtraDataValue('Order', $orderId, 'id'),
            'ifood_code' => $this->getIfoodExtraDataValue('Order', $orderId, 'code'),
            'merchant_id' => $this->getIfoodExtraDataValue('Order', $orderId, 'merchant_id'),
            'remote_order_state' => $this->getIfoodExtraDataValue('Order', $orderId, 'remote_order_state'),
            'order_type' => $this->getIfoodExtraDataValue('Order', $orderId, 'order_type'),
            'order_timing' => $this->getIfoodExtraDataValue('Order', $orderId, 'order_timing'),
            'delivered_by' => $this->getIfoodExtraDataValue('Order', $orderId, 'delivered_by'),
            'delivery_mode' => $this->getIfoodExtraDataValue('Order', $orderId, 'delivery_mode'),
            'takeout_mode' => $this->getIfoodExtraDataValue('Order', $orderId, 'takeout_mode'),
            'takeout_date_time' => $this->getIfoodExtraDataValue('Order', $orderId, 'takeout_date_time'),
            'dine_in_date_time' => $this->getIfoodExtraDataValue('Order', $orderId, 'dine_in_date_time'),
            'pickup_code' => $this->getIfoodExtraDataValue('Order', $orderId, 'pickup_code'),
            'pickup_area_code' => $this->getIfoodExtraDataValue('Order', $orderId, 'pickup_area_code'),
            'pickup_area_type' => $this->getIfoodExtraDataValue('Order', $orderId, 'pickup_area_type'),
            'handover_code' => $this->getIfoodExtraDataValue('Order', $orderId, 'handover_code'),
            'locator' => $this->getIfoodExtraDataValue('Order', $orderId, 'locator'),
            'handover_page_url' => $this->getIfoodExtraDataValue('Order', $orderId, 'handover_page_url'),
            'handover_confirmation_url' => $this->getIfoodExtraDataValue('Order', $orderId, 'handover_confirmation_url'),
            'virtual_phone' => $this->getIfoodExtraDataValue('Order', $orderId, 'virtual_phone'),
            'customer_name' => $this->getIfoodExtraDataValue('Order', $orderId, 'customer_name'),
            'customer_phone' => $this->getIfoodExtraDataValue('Order', $orderId, 'customer_phone'),
            'customer_document' => $this->getIfoodExtraDataValue('Order', $orderId, 'customer_document'),
            'customer_document_type' => $this->getIfoodExtraDataValue('Order', $orderId, 'customer_document_type'),
            'tax_document_requested' => $this->getIfoodExtraDataValue('Order', $orderId, 'tax_document_requested'),
            'address_display' => $this->getIfoodExtraDataValue('Order', $orderId, 'address_display'),
            'address_poi_address' => $this->getIfoodExtraDataValue('Order', $orderId, 'address_poi_address'),
            'address_street_name' => $this->getIfoodExtraDataValue('Order', $orderId, 'address_street_name'),
            'address_street_number' => $this->getIfoodExtraDataValue('Order', $orderId, 'address_street_number'),
            'address_district' => $this->getIfoodExtraDataValue('Order', $orderId, 'address_district'),
            'address_city' => $this->getIfoodExtraDataValue('Order', $orderId, 'address_city'),
            'address_state' => $this->getIfoodExtraDataValue('Order', $orderId, 'address_state'),
            'address_postal_code' => $this->getIfoodExtraDataValue('Order', $orderId, 'address_postal_code'),
            'address_reference' => $this->getIfoodExtraDataValue('Order', $orderId, 'address_reference'),
            'address_complement' => $this->getIfoodExtraDataValue('Order', $orderId, 'address_complement'),
            'remark' => $this->getIfoodExtraDataValue('Order', $orderId, 'remark'),
            'payment_liability' => $this->getIfoodExtraDataValue('Order', $orderId, 'payment_liability'),
            'payment_wallet_name' => $this->getIfoodExtraDataValue('Order', $orderId, 'payment_wallet_name'),
            'voucher_code' => $this->getIfoodExtraDataValue('Order', $orderId, 'voucher_code'),
            'discount_total' => $this->getIfoodExtraDataValue('Order', $orderId, 'discount_total'),
            'ifood_subsidy' => $this->getIfoodExtraDataValue('Order', $orderId, 'ifood_subsidy'),
            'merchant_subsidy' => $this->getIfoodExtraDataValue('Order', $orderId, 'merchant_subsidy'),
            'scheduled_start' => $this->getIfoodExtraDataValue('Order', $orderId, 'scheduled_start'),
            'scheduled_end' => $this->getIfoodExtraDataValue('Order', $orderId, 'scheduled_end'),
            'delivery_date_time' => $this->getIfoodExtraDataValue('Order', $orderId, 'delivery_date_time'),
            'preparation_start' => $this->getIfoodExtraDataValue('Order', $orderId, 'preparation_start'),
            'is_scheduled' => $this->getIfoodExtraDataValue('Order', $orderId, 'is_scheduled'),
            'handshake_event_type' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_event_type'),
            'handshake_dispute_id' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_dispute_id'),
            'handshake_created_at' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_created_at'),
            'handshake_action' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_action'),
            'handshake_type' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_type'),
            'handshake_group' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_group'),
            'handshake_message' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_message'),
            'handshake_expires_at' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_expires_at'),
            'handshake_timeout_action' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_timeout_action'),
            'handshake_accept_reasons' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_accept_reasons'),
            'handshake_alternatives_json' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_alternatives_json'),
            'handshake_alternative_id' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_alternative_id'),
            'handshake_alternative_type' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_alternative_type'),
            'handshake_alternative_amount_value' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_alternative_amount_value'),
            'handshake_alternative_amount_currency' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_alternative_amount_currency'),
            'handshake_alternative_time_minutes' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_alternative_time_minutes'),
            'handshake_alternative_reason' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_alternative_reason'),
            'handshake_evidences_json' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_evidences_json'),
            'handshake_evidence_url' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_evidence_url'),
            'handshake_evidence_content_type' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_evidence_content_type'),
            'handshake_selected_alternative_json' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_selected_alternative_json'),
            'handshake_settlement_status' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_settlement_status'),
            'handshake_settlement_reason' => $this->getIfoodExtraDataValue('Order', $orderId, 'handshake_settlement_reason'),
            'last_event_type' => $this->getIfoodExtraDataValue('Order', $orderId, 'last_event_type'),
            'last_event_at' => $this->getIfoodExtraDataValue('Order', $orderId, 'last_event_at'),
            'last_action' => $this->getIfoodExtraDataValue('Order', $orderId, 'last_action'),
            'last_action_at' => $this->getIfoodExtraDataValue('Order', $orderId, 'last_action_at'),
            'last_action_errno' => $this->getIfoodExtraDataValue('Order', $orderId, 'last_action_errno'),
            'last_action_message' => $this->getIfoodExtraDataValue('Order', $orderId, 'last_action_message'),
            'cancel_reason' => $this->getIfoodExtraDataValue('Order', $orderId, 'cancel_reason'),
            'webhook_event_id' => $this->getIfoodExtraDataValue('Order', $orderId, 'webhook_event_id'),
            'webhook_event_type' => $this->getIfoodExtraDataValue('Order', $orderId, 'webhook_event_type'),
            'webhook_event_at' => $this->getIfoodExtraDataValue('Order', $orderId, 'webhook_event_at'),
            'webhook_received_at' => $this->getIfoodExtraDataValue('Order', $orderId, 'webhook_received_at'),
            'webhook_processed_at' => $this->getIfoodExtraDataValue('Order', $orderId, 'webhook_processed_at'),
            'last_integration_id' => $this->getIfoodExtraDataValue('Order', $orderId, 'last_integration_id'),
        ];

        $storedRemoteId = $this->normalizeString($state['ifood_id'] ?? null);
        $storedDisplayId = $this->normalizeString($state['ifood_code'] ?? null);
        if ($storedDisplayId !== '' && ($storedDisplayId === $storedRemoteId || str_contains($storedDisplayId, '-'))) {
            $state['ifood_code'] = '';
        }

        $otherInformations = $this->getDecodedOrderOtherInformations($order);
        $latestEventType = $this->normalizeString($otherInformations['latest_event_type'] ?? null);
        if ($latestEventType !== '' && is_array($otherInformations[$latestEventType] ?? null)) {
            $payload = $otherInformations[$latestEventType];
            $state['last_event_type'] = $state['last_event_type'] ?: $latestEventType;
            $state['last_event_at'] = $state['last_event_at'] ?: $this->extractEventTimestamp($payload);
            $state['ifood_id'] = $state['ifood_id'] ?: $this->normalizeString($payload['orderId'] ?? null);
            $state['merchant_id'] = $state['merchant_id'] ?: $this->normalizeString($payload['merchantId'] ?? null);
            $state['remote_order_state'] = $state['remote_order_state'] ?: $this->resolveRemoteOrderStateByEventCode($latestEventType);

            if (is_array($payload['order'] ?? null)) {
                $snapshot = $this->extractOrderDetailSnapshot($payload['order']);
                $state['ifood_code'] = $state['ifood_code'] ?: $this->normalizeString($snapshot['code'] ?? null);
                foreach ($snapshot as $fieldName => $fieldValue) {
                    if (($state[$fieldName] ?? '') === '' && $fieldValue !== '') {
                        $state[$fieldName] = $fieldValue;
                    }
                }
            }
        }

        $storedOrderDetails = $this->findStoredIfoodOrderDetails($order);
        if ($storedOrderDetails !== []) {
            $state['ifood_code'] = $state['ifood_code'] ?: $this->normalizeString($storedOrderDetails['displayId'] ?? null);
            foreach ($this->extractOrderDetailSnapshot($storedOrderDetails) as $fieldName => $fieldValue) {
                if (($state[$fieldName] ?? '') === '' && $fieldValue !== '') {
                    $state[$fieldName] = $fieldValue;
                }
            }
        }

        return $state;
    }

    public function getOrderHomologationSnapshot(Order $order): array
    {
        $this->init();

        $otherInformations = $this->getDecodedOrderOtherInformations($order);
        $latestEventType = $this->normalizeString($otherInformations['latest_event_type'] ?? null);
        $payload = $latestEventType !== '' && is_array($otherInformations[$latestEventType] ?? null)
            ? $otherInformations[$latestEventType]
            : [];
        $orderPayload = is_array($payload['order'] ?? null) ? $payload['order'] : [];

        if ($orderPayload === []) {
            $orderPayload = $this->findStoredIfoodOrderDetails($order);
        }

        if ($orderPayload === []) {
            return [
                'financial' => null,
                'payment' => null,
                'customer' => null,
                'delivery' => null,
                'address' => null,
                'notes' => null,
                'identifiers' => null,
                'raw_payload_available' => false,
            ];
        }

        $delivery = is_array($orderPayload['delivery'] ?? null) ? $orderPayload['delivery'] : [];
        $deliveryAddress = is_array($delivery['deliveryAddress'] ?? null) ? $delivery['deliveryAddress'] : [];
        $customer = is_array($orderPayload['customer'] ?? null) ? $orderPayload['customer'] : [];
        $phone = is_array($customer['phone'] ?? null) ? $customer['phone'] : [];
        $payments = is_array($orderPayload['payments'] ?? null) ? $orderPayload['payments'] : [];
        $methods = is_array($payments['methods'] ?? null) ? $payments['methods'] : [];
        $firstMethod = is_array($methods[0] ?? null) ? $methods[0] : [];
        $total = is_array($orderPayload['total'] ?? null) ? $orderPayload['total'] : [];
        $additionalFees = is_array($orderPayload['additionalFees'] ?? null) ? $orderPayload['additionalFees'] : [];
        $benefitSnapshot = $this->extractOrderBenefitSnapshot($orderPayload);
        $additionalFeeSnapshot = $this->extractAdditionalFeeSnapshot($additionalFees);

        $itemsTotal = round((float) ($total['subTotal'] ?? 0), 2);
        $deliveryFee = round((float) ($total['deliveryFee'] ?? 0), 2);
        $additionalFeesTotal = round(
            (float) ($total['additionalFees'] ?? $additionalFeeSnapshot['total']),
            2
        );
        $serviceFee = $additionalFeeSnapshot['merchant_service_fee'];
        $smallOrderFee = $additionalFeeSnapshot['merchant_small_order_fee'];
        $mealTopUpFee = $additionalFeeSnapshot['merchant_meal_top_up_fee'];
        $discountTotal = round((float) (($total['benefits'] ?? null) ?: ($benefitSnapshot['discount_total'] ?? 0)), 2);
        $ifoodSubsidy = round((float) ($benefitSnapshot['ifood_subsidy'] ?? 0), 2);
        $merchantSubsidy = round((float) ($benefitSnapshot['merchant_subsidy'] ?? 0), 2);
        $customerTotal = round(
            (float) ($total['orderAmount'] ?? max(0, $itemsTotal + $deliveryFee + $additionalFeesTotal - $discountTotal)),
            2
        );
        $amountPaid = round((float) ($payments['prepaid'] ?? 0), 2);
        $amountPending = round((float) ($payments['pending'] ?? 0), 2);
        $customerNeedPayingMoney = $amountPending > 0 ? $amountPending : $customerTotal;
        $changeFor = round((float) ($firstMethod['cash']['changeFor'] ?? 0), 2);
        $changeAmount = $changeFor > $customerNeedPayingMoney
            ? round(max(0, $changeFor - $customerNeedPayingMoney), 2)
            : 0.0;
        $isPaidOnline = $amountPaid > 0 && $amountPending <= 0.009;
        $deliveredBy = strtoupper($this->normalizeString($delivery['deliveredBy'] ?? null));
        $deliveryMode = $this->normalizeString($delivery['mode'] ?? ($delivery['deliveryMode'] ?? null));
        $isStoreDelivery = $this->isMerchantDeliveryContext($deliveredBy, $deliveryMode);
        $merchantAdditionalFeeTotal = $additionalFeeSnapshot['merchant_total'];
        $storeReceivableTotal = round(max(
            0,
            $itemsTotal
                + ($isStoreDelivery ? $deliveryFee : 0.0)
                - $merchantSubsidy
                - $merchantAdditionalFeeTotal
        ), 2);

        return [
            'financial' => [
                'currency' => 'BRL',
                'items_total' => $itemsTotal,
                'delivery_fee' => $deliveryFee,
                'service_fee' => $serviceFee,
                'small_order_fee' => $smallOrderFee,
                'meal_top_up_fee' => $mealTopUpFee,
                'additional_fees_total' => $additionalFeesTotal,
                'merchant_additional_fee_total' => $merchantAdditionalFeeTotal,
                'tip_total' => 0.0,
                'subtotal_before_discounts' => round($itemsTotal + $deliveryFee + $additionalFeesTotal, 2),
                'discount_total' => $discountTotal,
                'store_discount_total' => $merchantSubsidy,
                'platform_discount_total' => $ifoodSubsidy,
                'store_non_delivery_discount_total' => round((float) ($benefitSnapshot['store_non_delivery_discount_total'] ?? 0), 2),
                'platform_non_delivery_discount_total' => round((float) ($benefitSnapshot['platform_non_delivery_discount_total'] ?? 0), 2),
                'store_delivery_discount_total' => round((float) ($benefitSnapshot['store_delivery_discount_total'] ?? 0), 2),
                'platform_delivery_discount_total' => round((float) ($benefitSnapshot['platform_delivery_discount_total'] ?? 0), 2),
                'promotions_total' => $discountTotal,
                'items_discount_total' => 0.0,
                'delivery_discount_total' => round(
                    (float) ($benefitSnapshot['store_delivery_discount_total'] ?? 0)
                        + (float) ($benefitSnapshot['platform_delivery_discount_total'] ?? 0),
                    2
                ),
                'coupon_discount_total' => 0.0,
                'customer_total' => $customerTotal,
                'customer_need_paying_money' => $customerNeedPayingMoney,
                'store_receivable_total' => $storeReceivableTotal,
                'real_pay_total' => $amountPaid,
                'refund_total' => 0.0,
                'store_charged_delivery_price' => $deliveryFee,
                'shop_paid_money' => 0.0,
                'ifood_subsidy' => $ifoodSubsidy,
                'merchant_subsidy' => $merchantSubsidy,
                'payment_brand' => $this->normalizeString($firstMethod['card']['brand'] ?? null),
                'change_for' => $changeFor,
            ],
            'payment' => [
                'pay_type' => $this->normalizeString($firstMethod['type'] ?? null),
                'pay_method' => $this->normalizeString($firstMethod['method'] ?? null),
                'pay_channel' => $this->normalizeString($firstMethod['card']['brand'] ?? ($firstMethod['method'] ?? null)),
                'selected_payment_label' => $this->normalizeString($firstMethod['method'] ?? null),
                'amount_paid' => $amountPaid,
                'amount_pending' => $amountPending,
                'collect_on_delivery_amount' => $amountPending,
                'customer_need_paying_money' => $customerNeedPayingMoney,
                'shop_paid_money' => 0.0,
                'change_for' => $changeFor,
                'change_amount' => $changeAmount,
                'needs_change' => $changeAmount > 0.009,
                'is_fully_paid' => $amountPending <= 0.009,
                'is_paid_online' => $isPaidOnline,
            ],
            'customer' => [
                'name' => $this->normalizeString($customer['name'] ?? null),
                'phone' => $this->normalizeString($phone['number'] ?? null),
            ],
            'delivery' => [
                'delivered_by' => $deliveredBy,
                'delivery_mode' => $deliveryMode,
                'is_store_delivery' => $isStoreDelivery,
                'is_platform_delivery' => !$isStoreDelivery,
            ],
            'address' => [
                'display' => $this->normalizeString($deliveryAddress['formattedAddress'] ?? null),
                'street_name' => $this->normalizeString($deliveryAddress['streetName'] ?? null),
                'street_number' => $this->normalizeString($deliveryAddress['streetNumber'] ?? null),
                'district' => $this->normalizeString($deliveryAddress['neighborhood'] ?? null),
                'city' => $this->normalizeString($deliveryAddress['city'] ?? null),
                'state' => $this->normalizeString($deliveryAddress['state'] ?? null),
                'postal_code' => $this->normalizeString($deliveryAddress['postalCode'] ?? null),
                'reference' => $this->normalizeString($deliveryAddress['reference'] ?? null),
                'complement' => $this->normalizeString($deliveryAddress['complement'] ?? null),
            ],
            'notes' => [
                'remark' => $this->extractOrderRemarkFromPayload($orderPayload),
            ],
            'identifiers' => [
                'ifood_code' => $this->normalizeString($orderPayload['displayId'] ?? null),
                'ifood_id' => $this->normalizeString($payload['orderId'] ?? null),
            ],
            'raw_payload_available' => true,
        ];
    }

    public function getSelfDeliveryConfirmationUrl(): string
    {
        return self::SELF_DELIVERY_CONFIRMATION_URL;
    }

    private function persistProviderIntegrationState(People $provider, array $fields): void
    {
        foreach ($fields as $fieldName => $value) {
            $this->upsertIfoodExtraDataValue('People', (int) $provider->getId(), (string) $fieldName, $value);
        }
    }

    private function normalizeMerchantStatusLabel(?string $status): string
    {
        return match (strtoupper($this->normalizeString($status))) {
            'AVAILABLE', 'ONLINE', 'OPEN' => 'Online',
            'UNAVAILABLE', 'OFFLINE', 'CLOSED', 'INACTIVE' => 'Offline',
            default => 'Indefinido',
        };
    }

    private function normalizeMerchantPayload(mixed $merchant): ?array
    {
        if (!is_array($merchant)) {
            return null;
        }

        $merchantId = $this->normalizeString(
            $merchant['id']
                ?? $merchant['merchantId']
                ?? $merchant['merchant_id']
                ?? null
        );

        if ($merchantId === '') {
            return null;
        }

        $merchantStatus = strtoupper($this->normalizeString(
            $merchant['status']
                ?? $merchant['state']
                ?? $merchant['merchantStatus']
                ?? null
        ));

        return [
            'merchant_id' => $merchantId,
            'name' => $this->normalizeString(
                $merchant['name']
                    ?? $merchant['displayName']
                    ?? $merchant['merchantName']
                    ?? null
            ),
            'status' => $merchantStatus,
            'status_label' => $this->normalizeMerchantStatusLabel($merchantStatus),
            'city' => $this->normalizeString($merchant['city'] ?? null),
            'raw' => $merchant,
        ];
    }

    private function normalizeMerchantListPayload(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $merchantRows = [];
        if (array_is_list($payload)) {
            $merchantRows = $payload;
        } elseif (is_array($payload['merchants'] ?? null)) {
            $merchantRows = $payload['merchants'];
        } elseif (is_array($payload['items'] ?? null)) {
            $merchantRows = $payload['items'];
        } elseif (is_array($payload['data'] ?? null)) {
            if (array_is_list($payload['data'])) {
                $merchantRows = $payload['data'];
            } elseif (is_array($payload['data']['merchants'] ?? null)) {
                $merchantRows = $payload['data']['merchants'];
            } elseif (is_array($payload['data']['items'] ?? null)) {
                $merchantRows = $payload['data']['items'];
            }
        }

        $normalized = [];
        foreach ($merchantRows as $merchantRow) {
            $merchant = $this->normalizeMerchantPayload($merchantRow);
            if ($merchant === null) {
                continue;
            }

            $normalized[] = $merchant;
        }

        return $normalized;
    }

    private function listMerchantsRaw(): array
    {
        $this->init();
        $token = $this->getAccessToken();
        if (!$token) {
            return [
                'errno' => 10001,
                'errmsg' => 'Token iFood indisponivel',
                'status' => 401,
                'data' => [
                    'merchants' => [],
                ],
            ];
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                self::API_BASE_URL . '/merchant/v1.0/merchants',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                    ],
                ]
            );

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
            $decoded = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                $decoded = [];
            }

            if ($statusCode < 200 || $statusCode >= 300) {
                return [
                    'errno' => $statusCode > 0 ? $statusCode : 1,
                    'errmsg' => $this->normalizeString(
                        $decoded['message']
                            ?? $decoded['details']
                            ?? $decoded['description']
                            ?? 'Falha ao listar lojas iFood'
                    ),
                    'status' => $statusCode,
                    'data' => [
                        'merchants' => [],
                        'raw' => $decoded,
                    ],
                ];
            }

            return [
                'errno' => 0,
                'errmsg' => 'ok',
                'status' => $statusCode,
                'data' => [
                    'merchants' => $this->normalizeMerchantListPayload($decoded),
                    'raw' => $decoded,
                ],
            ];
        } catch (\Throwable $e) {
            self::$logger->error('iFood merchants list request failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'errno' => 1,
                'errmsg' => 'Falha ao consultar lojas iFood',
                'status' => 500,
                'data' => [
                    'merchants' => [],
                ],
            ];
        }
    }

    /* GET /merchant/v1.0/merchants/{merchantId}
     * Retorna detalhe completo da loja incluindo o campo "status" (AVAILABLE, UNAVAILABLE, etc.)
     * que o endpoint de listagem nao inclui.
     */
    private function getMerchantDetailRaw(string $merchantId, string $token): array
    {
        try {
            $response   = $this->httpClient->request('GET',
                self::API_BASE_URL . '/merchant/v1.0/merchants/' . rawurlencode($merchantId),
                ['headers' => ['Authorization' => 'Bearer ' . $token]]);
            $statusCode = $response->getStatusCode();
            $content    = (string) $response->getContent(false);
            $decoded    = json_decode($content, true);
            if (!is_array($decoded)) $decoded = [];

            if ($statusCode < 200 || $statusCode >= 300) {
                return ['errno' => $statusCode, 'errmsg' => 'Falha ao obter detalhe da loja', 'data' => null];
            }

            return ['errno' => 0, 'errmsg' => 'ok', 'data' => $decoded];
        } catch (\Throwable $e) {
            self::$logger->warning('iFood getMerchantDetailRaw failed', ['error' => $e->getMessage()]);
            return ['errno' => 1, 'errmsg' => $e->getMessage(), 'data' => null];
        }
    }

    public function getMerchantDetail(People $provider): array
    {
        $this->init();
        $stored = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($stored['merchant_id'] ?? null);
        if ($merchantId === '') {
            return ['errno' => 10002, 'errmsg' => 'Loja iFood nao conectada.', 'data' => null];
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return ['errno' => 10001, 'errmsg' => 'Token iFood indisponivel.', 'data' => null];
        }

        $detail = $this->getMerchantDetailRaw($merchantId, $token);
        if ((int) ($detail['errno'] ?? 1) !== 0 || !is_array($detail['data'] ?? null)) {
            return [
                'errno' => (int) ($detail['errno'] ?? 1),
                'errmsg' => $this->normalizeString($detail['errmsg'] ?? 'Falha ao obter detalhes da loja'),
                'data' => null,
            ];
        }

        return [
            'errno' => 0,
            'errmsg' => 'ok',
            'data' => $detail['data'],
        ];
    }

    public function listMerchants(): array
    {
        return $this->listMerchantsRaw();
    }

    public function isAuthAvailable(): bool
    {
        $this->init();

        return $this->getAccessToken() !== null;
    }

    public function countEligibleProducts(People $provider): int
    {
        $connection = $this->entityManager->getConnection();
        $sql = <<<SQL
            SELECT COUNT(1)
            FROM product p
            WHERE p.company_id = :providerId
              AND p.active = 1
              AND p.type IN ('manufactured', 'custom', 'product')
        SQL;

        return (int) $connection->fetchOne($sql, [
            'providerId' => (int) $provider->getId(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Catalog v2                                                          */
    /* ------------------------------------------------------------------ */

    private const CATALOG_V2_BASE = 'https://merchant-api.ifood.com.br/catalog/v2.0/merchants/';

    public function listSelectableMenuProducts(People $provider): array
    {
        $this->init();

        $state      = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($state['merchant_id'] ?? null);

        $rows = $this->fetchCatalogProducts($provider);

        /* mapa de externalCodes já publicados no catálogo iFood */
        $remoteByEc = [];
        if ($merchantId !== '') {
            $remoteByEc = $this->buildIfoodCatalogItemExternalCodeIndex(
                $merchantId,
                $this->fetchIfoodCatalogItemsV2($merchantId),
                true
            );
        }

        $products = array_map(
            fn(array $row) => $this->buildIfoodMenuProductView($row, $remoteByEc),
            $rows
        );

        return [
            'provider_id'           => $provider->getId(),
            'minimum_required_items' => 1,
            'eligible_product_count' => count(array_filter($products, fn(array $p) => $p['eligible'])),
            'products'              => $products,
        ];
    }

    private function buildIfoodMenuProductView(array $row, array $remoteByEc): array
    {
        $productId    = (int) ($row['id'] ?? 0);
        $name         = trim((string) ($row['name'] ?? ''));
        $price        = round((float) ($row['price'] ?? 0), 2);
        $type         = strtolower(trim((string) ($row['type'] ?? '')));
        $categoryId   = isset($row['category_id']) && $row['category_id'] !== null ? (int) $row['category_id'] : null;
        $categoryName = $categoryId !== null ? trim((string) ($row['category_name'] ?? '')) : null;
        $modifierGroups = is_array($row['modifier_groups'] ?? null) ? $row['modifier_groups'] : [];
        $modifierGroups = $this->enrichIfoodModifierGroupsWithRemoteOptions($modifierGroups, $remoteByEc);
        $hasRequiredModifiers = !empty(array_filter($modifierGroups, static function (array $group): bool {
            return !empty($group['required']) || (int) ($group['minimum'] ?? 0) >= 1;
        }));
        $allowZeroPriceByGroups = $type === 'custom' && $hasRequiredModifiers;

        $blockers = [];
        if ($name === '')    $blockers[] = 'Produto sem nome';
        if (!$categoryId)    $blockers[] = 'Produto sem categoria';
        if ($price <= 0 && !$allowZeroPriceByGroups) $blockers[] = 'Produto com preco invalido';

        $ec = (string) $productId;

        $remoteEntry = $remoteByEc[$ec] ?? null;

        $view = [
            'id'               => $productId,
            'name'             => $name,
            'description'      => trim((string) ($row['description'] ?? '')),
            'price'            => $price,
            'type'             => (string) ($row['type'] ?? ''),
            'category'         => $categoryId !== null ? ['id' => $categoryId, 'name' => $categoryName] : null,
            'has_required_modifiers' => $hasRequiredModifiers,
            'modifier_groups'  => $modifierGroups,
            'options'          => $this->flattenIfoodModifierOptions($modifierGroups),
            'active'           => (int) ($row['product_active'] ?? 1) === 1,
            'eligible'         => empty($blockers),
            'blockers'         => $blockers,
            'published_remotely' => $remoteEntry !== null,
            'ifood_item_id'    => !empty($remoteEntry['option_id']) ? null : ($remoteEntry['item_id'] ?? null),
            'ifood_option_id'  => $remoteEntry['option_id'] ?? null,
            'ifood_status'     => $remoteEntry['status'] ?? null,
            'ifood_match_source' => $remoteEntry['match_source'] ?? null,
            'cover_image_url'  => $this->buildPublicFileDownloadUrl($row['cover_file_id'] ?? null),
        ];

        $view['sync'] = $this->buildIfoodProductSyncState($view, $remoteEntry !== null);

        return $view;
    }

    private function enrichIfoodModifierGroupsWithRemoteOptions(array $modifierGroups, array $remoteByEc): array
    {
        return array_map(function (array $group) use ($remoteByEc): array {
            $options = is_array($group['options'] ?? null) ? $group['options'] : [];
            $group['options'] = array_map(function (array $option) use ($remoteByEc): array {
                $relationId = (int) ($option['id'] ?? 0);
                $childProductId = (int) ($option['child_product_id'] ?? 0);
                $remoteEntry = null;

                foreach (['option-' . $relationId, (string) $childProductId] as $candidate) {
                    if ($candidate !== '' && !empty($remoteByEc[$candidate]['option_id'])) {
                        $remoteEntry = $remoteByEc[$candidate];
                        break;
                    }
                }

                if ($remoteEntry) {
                    $option['ifood_option_id'] = $remoteEntry['option_id'];
                    $option['ifood_status'] = $remoteEntry['status'] ?? null;
                    $option['ifood_item_id'] = $remoteEntry['item_id'] ?? null;
                    $option['ifood_match_source'] = $remoteEntry['match_source'] ?? null;
                }

                return $option;
            }, $options);

            return $group;
        }, $modifierGroups);
    }

    private function flattenIfoodModifierOptions(array $modifierGroups): array
    {
        $options = [];
        foreach ($modifierGroups as $group) {
            foreach (($group['options'] ?? []) as $option) {
                if (is_array($option)) {
                    $options[] = $option;
                }
            }
        }

        return $options;
    }

    private function normalizeCatalogSyncHashPayload(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeCatalogSyncHashPayload($item);
            }

            if (!array_is_list($normalized)) {
                ksort($normalized);
            }

            return $normalized;
        }

        if (is_float($value)) {
            return round($value, 4);
        }

        if (is_bool($value) || is_int($value) || is_string($value) || $value === null) {
            return $value;
        }

        return (string) $value;
    }

    private function buildCatalogSyncHash(array $payload): string
    {
        $json = json_encode(
            $this->normalizeCatalogSyncHashPayload($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return hash('sha256', is_string($json) ? $json : '');
    }

    private function buildIfoodProductSyncHash(array $product): string
    {
        return $this->buildCatalogSyncHash([
            'id' => (int) ($product['id'] ?? 0),
            'name' => trim((string) ($product['name'] ?? '')),
            'description' => trim((string) ($product['description'] ?? '')),
            'price' => round((float) ($product['price'] ?? 0), 2),
            'type' => trim((string) ($product['type'] ?? '')),
            'active' => !array_key_exists('active', $product) || (bool) $product['active'],
            'category_id' => (int) ($product['category']['id'] ?? 0),
            'category_name' => trim((string) ($product['category']['name'] ?? '')),
            'has_required_modifiers' => !empty($product['has_required_modifiers']),
            'modifier_groups' => $product['modifier_groups'] ?? [],
            'cover_image_url' => trim((string) ($product['cover_image_url'] ?? '')),
        ]);
    }

    private function buildIfoodCategorySyncHash(array $category): string
    {
        return $this->buildCatalogSyncHash([
            'id' => (int) ($category['id'] ?? 0),
            'name' => trim((string) ($category['name'] ?? '')),
            'color' => trim((string) ($category['color'] ?? '')),
            'icon' => trim((string) ($category['icon'] ?? '')),
            'parent_id' => (int) ($category['parent_id'] ?? 0),
        ]);
    }

    private function buildIfoodProductSyncState(array $product, ?bool $publishedOverride = null): array
    {
        $productId = (int) ($product['id'] ?? 0);
        $published = $publishedOverride ?? !empty($product['published_remotely']);
        $currentHash = $this->buildIfoodProductSyncHash($product);
        $storedHash = $this->getIfoodExtraDataValue('Product', $productId, 'sync_hash') ?? '';
        $hasStoredHash = $storedHash !== '';
        $dirty = $published && (!$hasStoredHash || !hash_equals($storedHash, $currentHash));
        $remoteId = trim((string) ($product['ifood_item_id'] ?? ($product['ifood_option_id'] ?? '')));

        return [
            'platform' => 'ifood',
            'remote_id' => $remoteId !== '' ? $remoteId : null,
            'published' => (bool) $published,
            'eligible' => !empty($product['eligible']),
            'synced' => (bool) ($published && !$dirty),
            'dirty' => (bool) $dirty,
            'last_synced_at' => $this->getIfoodExtraDataValue('Product', $productId, 'sync_synced_at'),
            'status' => !$published ? 'not_synced' : ($dirty ? 'dirty' : 'synced'),
        ];
    }

    private function buildIfoodCategorySyncState(array $category, array $productIds, bool $published, bool $eligible): array
    {
        $categoryId = (int) ($category['id'] ?? 0);
        $currentHash = $this->buildIfoodCategorySyncHash($category);
        $storedHash = $this->getIfoodExtraDataValue('Category', $categoryId, 'sync_hash') ?? '';
        $hasStoredHash = $storedHash !== '';
        $dirty = $published && (!$hasStoredHash || !hash_equals($storedHash, $currentHash));
        $remoteId = $this->getIfoodExtraDataValue('Category', $categoryId, 'code');

        return [
            'platform' => 'ifood',
            'remote_id' => $remoteId,
            'published' => (bool) $published,
            'eligible' => $eligible,
            'synced' => (bool) ($published && !$dirty),
            'dirty' => (bool) $dirty,
            'last_synced_at' => $this->getIfoodExtraDataValue('Category', $categoryId, 'sync_synced_at'),
            'status' => !$published ? 'not_synced' : ($dirty ? 'dirty' : 'synced'),
            'product_ids' => array_values(array_unique(array_map('intval', $productIds))),
        ];
    }

    private function fetchCatalogCategories(People $provider): array
    {
        $sql = <<<SQL
            SELECT
                c.id,
                c.name,
                c.color,
                c.icon,
                c.parent_id
            FROM category c
            WHERE c.company_id = :providerId
              AND c.context = 'products'
            ORDER BY c.name ASC
        SQL;

        return $this->entityManager->getConnection()->fetchAllAssociative($sql, [
            'providerId' => (int) $provider->getId(),
        ]);
    }

    public function getCatalogSyncStatus(People $provider): array
    {
        $this->init();

        $storedState = $this->getStoredIntegrationState($provider);
        $productsResponse = $this->listSelectableMenuProducts($provider);
        $products = is_array($productsResponse['products'] ?? null) ? $productsResponse['products'] : [];
        $categories = $this->fetchCatalogCategories($provider);
        $categoryProductIds = [];
        $categoryEligibleProductIds = [];
        $categoryPublished = [];
        $eligibleProductIds = [];
        $publishedProductIds = [];

        $productStatuses = array_map(function (array $product) use (&$categoryProductIds, &$categoryEligibleProductIds, &$categoryPublished, &$eligibleProductIds, &$publishedProductIds): array {
            $productId = (int) ($product['id'] ?? 0);
            $categoryId = (int) ($product['category']['id'] ?? 0);
            $sync = is_array($product['sync'] ?? null) ? $product['sync'] : $this->buildIfoodProductSyncState($product);

            if ($categoryId > 0 && $productId > 0) {
                $categoryProductIds[$categoryId][] = $productId;
            }

            if (!empty($sync['eligible']) && $productId > 0) {
                $eligibleProductIds[] = $productId;
                if ($categoryId > 0) {
                    $categoryEligibleProductIds[$categoryId][] = $productId;
                }
            }

            if (!empty($sync['published']) && $productId > 0) {
                $publishedProductIds[] = $productId;
                if ($categoryId > 0) {
                    $categoryPublished[$categoryId] = true;
                }
            }

            return array_merge($sync, [
                'id' => $productId,
                'name' => $product['name'] ?? null,
                'category_id' => $categoryId ?: null,
                'blockers' => $product['blockers'] ?? [],
            ]);
        }, $products);

        $categoryStatuses = array_map(function (array $category) use ($categoryProductIds, $categoryEligibleProductIds, $categoryPublished): array {
            $categoryId = (int) ($category['id'] ?? 0);
            $productIds = $categoryProductIds[$categoryId] ?? [];
            $eligibleIds = $categoryEligibleProductIds[$categoryId] ?? [];
            $sync = $this->buildIfoodCategorySyncState(
                $category,
                $productIds,
                !empty($categoryPublished[$categoryId]) || $this->getIfoodExtraDataValue('Category', $categoryId, 'code') !== null,
                !empty($eligibleIds)
            );

            return array_merge($sync, [
                'id' => $categoryId,
                'name' => $category['name'] ?? null,
                'eligible_product_ids' => array_values(array_unique(array_map('intval', $eligibleIds))),
            ]);
        }, $categories);

        return [
            'platform' => [
                'key' => 'ifood',
                'label' => 'iFood',
                'active' => !empty($storedState['connected']) || !empty($storedState['remote_connected']),
                'connected' => !empty($storedState['connected']),
                'remote_connected' => !empty($storedState['remote_connected']),
                'store_code' => $storedState['ifood_code'] ?? $storedState['merchant_id'] ?? null,
                'last_sync_at' => $storedState['last_sync_at'] ?? null,
                'last_error_message' => $storedState['last_error_message'] ?? null,
            ],
            'products' => $productStatuses,
            'categories' => $categoryStatuses,
            'eligible_product_ids' => array_values(array_unique(array_map('intval', $eligibleProductIds))),
            'published_product_ids' => array_values(array_unique(array_map('intval', $publishedProductIds))),
            'minimum_required_items' => (int) ($productsResponse['minimum_required_items'] ?? 1),
        ];
    }

    private function markCategoriesCatalogSynced(People $provider, array $categoryIds): void
    {
        $categoryIdSet = array_flip(array_map('intval', $categoryIds));
        if (empty($categoryIdSet)) {
            return;
        }

        foreach ($this->fetchCatalogCategories($provider) as $category) {
            $categoryId = (int) ($category['id'] ?? 0);
            if ($categoryId <= 0 || !isset($categoryIdSet[$categoryId])) {
                continue;
            }

            $this->upsertIfoodExtraDataValue('Category', $categoryId, 'sync_hash', $this->buildIfoodCategorySyncHash($category));
            $this->upsertIfoodExtraDataValue('Category', $categoryId, 'sync_synced_at', date('Y-m-d H:i:s'));
        }
    }

    public function markProductsCatalogSynced(People $provider, array $productIds): void
    {
        $this->init();

        $rows = $this->fetchCatalogProducts($provider, $this->normalizeProductIds($productIds));
        if (empty($rows)) {
            return;
        }

        $categoryIds = [];
        foreach ($rows as $row) {
            $product = $this->buildIfoodMenuProductView($row, []);
            $productId = (int) ($product['id'] ?? 0);
            if ($productId <= 0 || empty($product['eligible'])) {
                continue;
            }

            $this->upsertIfoodExtraDataValue('Product', $productId, 'sync_hash', $this->buildIfoodProductSyncHash($product));
            $this->upsertIfoodExtraDataValue('Product', $productId, 'sync_synced_at', date('Y-m-d H:i:s'));

            $categoryId = (int) ($product['category']['id'] ?? 0);
            if ($categoryId > 0) {
                $categoryIds[] = $categoryId;
            }
        }

        $this->markCategoriesCatalogSynced($provider, $categoryIds);
    }

    public function publishMenu(People $provider, array $productIds = []): array
    {
        $this->init();

        $state      = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($state['merchant_id'] ?? null);
        if ($merchantId === '') {
            return ['errno' => 10002, 'errmsg' => 'Loja iFood nao conectada. Vincule o merchant_id antes de publicar o cardapio.'];
        }

        /* resolve catalogId */
        $catalogId  = $this->fetchIfoodDefaultCatalogId($merchantId);
        if ($catalogId === null) {
            return ['errno' => 10003, 'errmsg' => 'Nao foi possivel obter o catalogo iFood da loja.'];
        }

        /* produtos locais */
        $allProducts = $this->fetchCatalogProducts($provider);
        if (!empty($productIds)) {
            $idSet = array_flip(array_map('strval', $productIds));
            $allProducts = array_values(array_filter(
                $allProducts,
                fn(array $p) => isset($idSet[(string) $p['id']])
            ));
        }
        if (empty($allProducts)) {
            return ['errno' => 10002, 'errmsg' => 'Nenhum produto selecionado para publicar no iFood.'];
        }

        $remoteCategories = $this->fetchIfoodCatalogCategoriesV2($merchantId, $catalogId);
        $remoteCategoriesByName = [];
        foreach ($remoteCategories as $remoteCategory) {
            $remoteCategoryName = $this->normalizeString($remoteCategory['name'] ?? null);
            $remoteCategoryId   = $this->normalizeString($remoteCategory['id'] ?? null);
            if ($remoteCategoryName !== '' && $remoteCategoryId !== '') {
                $remoteCategoriesByName[$this->normalizeText($remoteCategoryName)] = $remoteCategoryId;
            }
        }

        $groupedProducts = [];
        foreach ($allProducts as $prod) {
            $groupCategoryId   = $this->normalizeString($prod['category_id'] ?? null);
            $groupCategoryName = $this->normalizeString($prod['category_name'] ?? null);
            $groupIfoodCode    = $this->normalizeString($prod['ifood_category_code'] ?? null);
            $groupKey          = $groupCategoryId !== '' ? $groupCategoryId : ($groupCategoryName !== '' ? $groupCategoryName : '__default__');

            if (!isset($groupedProducts[$groupKey])) {
                $groupedProducts[$groupKey] = [
                    'category_id'        => $groupCategoryId,
                    'category_name'      => $groupCategoryName !== '' ? $groupCategoryName : 'Produtos',
                    'ifood_category_code' => $groupIfoodCode,
                    'products'           => [],
                ];
            }

            $groupedProducts[$groupKey]['products'][] = $prod;
        }

        /* mapa de itens existentes por externalCode */
        $remoteItems = $this->fetchIfoodCatalogItemsV2($merchantId);
        $byEc        = $this->buildIfoodCatalogItemExternalCodeIndex($merchantId, $remoteItems, true);

        $pushed = 0;
        $errors = [];
        $pushedProductIds = [];
        $sequence = 0;
        foreach ($groupedProducts as $group) {
            $sequence++;
            $resolvedCategoryId = $this->resolveIfoodCatalogCategoryId(
                $merchantId,
                $catalogId,
                $group['category_name'],
                $remoteCategoriesByName,
                $sequence,
                (int) ($group['category_id'] ?: 0),
                $group['ifood_category_code'] ?? ''
            );

            if ($resolvedCategoryId === null) {
                $errors[] = [
                    'product_id'   => null,
                    'product_name' => $group['category_name'],
                    'http_status'  => null,
                    'ifood_body'   => null,
                    'error'        => 'Nao foi possivel obter ou criar a categoria no iFood.',
                    'sent_payload' => null,
                ];
                continue;
            }

            foreach ($group['products'] as $prod) {
                $ec       = (string) $prod['id'];
                $existing = $byEc[$ec] ?? null;

                $existingFlat = null;
                if (!empty($existing['item_id'])) {
                    $existingFlat = $this->fetchIfoodCatalogItemFlatV2($merchantId, $existing['item_id']);
                }

                $result = $this->upsertIfoodCatalogItemV2($merchantId, $prod, $existing, $resolvedCategoryId, $existingFlat);
                if ($result['ok']) {
                    $pushed++;
                    $pushedProductIds[] = (int) ($prod['id'] ?? 0);
                } else {
                    $errors[] = [
                        'product_id'   => $prod['id'],
                        'product_name' => $prod['name'] ?? '',
                        'http_status'  => $result['http_status'],
                        'ifood_body'   => $result['ifood_body'],
                        'error'        => $result['error'],
                        'sent_payload' => $result['sent_payload'] ?? null,
                    ];
                    self::$logger->warning('iFood catalog v2 upsert failed', [
                        'product_id'  => $prod['id'],
                        'http_status' => $result['http_status'],
                        'ifood_body'  => $result['ifood_body'],
                        'error'       => $result['error'],
                    ]);
                }
            }
        }

        /* atualiza published_remotely nos produtos locais */
        if ($pushed > 0) {
            $this->syncCatalogFromIfood($provider);
            $this->markProductsCatalogSynced($provider, $pushedProductIds);
        }

        $errno  = $pushed > 0 ? 0 : 1;
        $errmsg = $errno === 0 ? 'ok' : 'Nenhum produto publicado com sucesso.';

        return [
            'errno'  => $errno,
            'errmsg' => $errmsg,
            'data'   => [
                'merchant_id'    => $merchantId,
                'pushed_count'   => $pushed,
                'total_products' => count($allProducts),
                'error_count'    => count($errors),
                'errors'         => $errors,
            ],
        ];
    }

    public function syncCatalogFromIfood(People $provider): array
    {
        $this->init();

        $state      = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($state['merchant_id'] ?? null);
        if ($merchantId === '') {
            return ['errno' => 10002, 'errmsg' => 'Loja iFood nao conectada.'];
        }

        $items = $this->fetchIfoodCatalogItemsV2($merchantId);
        if (empty($items)) {
            return ['errno' => 0, 'errmsg' => 'Nenhum item encontrado no catalogo iFood.', 'data' => ['synced' => 0, 'total' => 0]];
        }

        $remoteByEc = $this->buildIfoodCatalogItemExternalCodeIndex($merchantId, $items, true);

        $synced = 0;
        $syncedProductIds = [];
        $syncedProductIdSet = [];
        $syncedItemIds = [];
        foreach ($remoteByEc as $externalCode => $remoteEntry) {
            $itemId = $this->normalizeString($remoteEntry['item_id'] ?? null);
            $optionId = $this->normalizeString($remoteEntry['option_id'] ?? null);
            if ($itemId === '' || !ctype_digit((string) $externalCode)) {
                continue;
            }

            $product = $this->entityManager->getRepository(Product::class)->findOneBy([
                'company' => $provider,
                'id'      => (int) $externalCode,
            ]);

            if ($product) {
                $productId = (int) $product->getId();
                if (isset($syncedProductIdSet[$productId])) {
                    continue;
                }
                if ($optionId === '' && !isset($syncedItemIds[$itemId])) {
                    $this->discoveryFoodCode($product, $itemId);
                    $syncedItemIds[$itemId] = true;
                }
                $synced++;
                $syncedProductIdSet[$productId] = true;
                $syncedProductIds[] = $productId;
            }
        }

        foreach ($items as $item) {
            $itemId       = $this->normalizeString($item['id'] ?? null);
            $itemName     = $this->normalizeString($item['name'] ?? null);
            if ($itemId === '' || isset($syncedItemIds[$itemId])) continue;

            $product = null;
            if (!$product && $itemName !== '') {
                $product = $this->entityManager->getRepository(Product::class)->findOneBy([
                    'company' => $provider,
                    'product' => $itemName,
                ]);
            }

            if ($product) {
                $productId = (int) $product->getId();
                if (isset($syncedProductIdSet[$productId])) {
                    continue;
                }
                $this->discoveryFoodCode($product, $itemId);
                $synced++;
                $syncedItemIds[$itemId] = true;
                $syncedProductIdSet[$productId] = true;
                $syncedProductIds[] = $productId;
            }
        }

        if ($synced > 0) {
            $this->entityManager->flush();
            $this->markProductsCatalogSynced($provider, $syncedProductIds);
        }

        return [
            'errno'  => 0,
            'errmsg' => 'ok',
            'data'   => ['total' => count($items), 'synced' => $synced],
        ];
    }

    private function fetchCatalogProducts(People $provider, array $productIds = []): array
    {
        $connection = $this->entityManager->getConnection();
        $params     = ['providerId' => (int) $provider->getId()];
        $sql = <<<SQL
            SELECT
                p.id,
                p.product AS name,
                p.description,
                p.price,
                p.type,
                p.active AS product_active,
                pf.file_id AS cover_file_id,
                c.id   AS category_id,
                c.name AS category_name,
                (
                    SELECT ed.data_value
                    FROM extra_data ed
                    INNER JOIN extra_fields ef ON ef.id = ed.extra_fields_id
                    WHERE ef.context  = 'iFood'
                      AND ef.field_name = 'code'
                      AND LOWER(ed.entity_name) = 'category'
                      AND ed.entity_id = c.id
                    ORDER BY ed.id DESC
                    LIMIT 1
                ) AS ifood_category_code
            FROM product p
            LEFT JOIN product_category pc ON pc.id = (
                SELECT MIN(pc2.id)
                FROM product_category pc2
                INNER JOIN category c2 ON c2.id = pc2.category_id
                WHERE pc2.product_id = p.id
            )
            LEFT JOIN category c ON c.id = pc.category_id
            LEFT JOIN product_file pf ON pf.id = (
                SELECT MIN(pf2.id)
                FROM product_file pf2
                WHERE pf2.product_id = p.id
            )
            WHERE p.company_id = :providerId
              AND (
                  p.active = 1
                  OR EXISTS (
                      SELECT 1
                      FROM extra_data ed_product
                      INNER JOIN extra_fields ef_product ON ef_product.id = ed_product.extra_fields_id
                      WHERE ef_product.context = 'iFood'
                        AND ef_product.field_name = 'code'
                        AND LOWER(ed_product.entity_name) = 'product'
                        AND ed_product.entity_id = p.id
                  )
              )
              AND p.type IN ('manufactured', 'custom', 'product')
        SQL;

        if (!empty($productIds)) {
            $placeholders = [];
            foreach ($productIds as $i => $pid) {
                $key = 'pid' . $i;
                $placeholders[]  = ':' . $key;
                $params[$key]    = (int) $pid;
            }
            $sql .= ' AND p.id IN (' . implode(', ', $placeholders) . ')';
        }

        $sql .= ' ORDER BY p.product ASC';

        $rows = $connection->fetchAllAssociative($sql, $params);

        return $this->attachCatalogModifierGroups($provider, $rows);
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

    private function fetchCatalogModifierRows(People $provider, array $productIds = []): array
    {
        $parentIds = $this->normalizeProductIds($productIds);
        if (empty($parentIds)) {
            return [];
        }

        $connection = $this->entityManager->getConnection();
        $params = [
            'providerId' => $provider->getId(),
        ];

        $placeholders = [];
        foreach ($parentIds as $index => $productId) {
            $key = 'parentProductId' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $productId;
        }

        $sql = <<<SQL
            SELECT
                group_parent.parent_product_id AS parent_product_id,
                pg.id AS product_group_id,
                pg.product_group AS product_group_name,
                pg.required AS group_required,
                pg.minimum AS group_minimum,
                pg.maximum AS group_maximum,
                pg.group_order AS group_order,
                pg.active AS group_active,
                pgp.id AS product_group_product_id,
                pgp.quantity AS child_quantity,
                pgp.price AS child_relation_price,
                pgp.active AS relation_active,
                child.id AS child_product_id,
                child.product AS child_product_name,
                child.description AS child_description,
                child.price AS child_base_price,
                child.sku AS child_sku,
                child.active AS child_active,
                child_pf.file_id AS child_cover_file_id
            FROM product_group pg
            INNER JOIN product_group_parent group_parent
                ON group_parent.product_group_id = pg.id
               AND group_parent.active = 1
            INNER JOIN product_group_product pgp
                ON %s
            INNER JOIN product parent
                ON parent.id = group_parent.parent_product_id
            INNER JOIN product child
                ON child.id = pgp.product_child_id
            LEFT JOIN product_file child_pf ON child_pf.id = (
                SELECT MIN(pf2.id)
                FROM product_file pf2
                WHERE pf2.product_id = child.id
            )
            WHERE parent.company_id = :providerId
              AND parent.active = 1
              AND pgp.product_type IN ('component', 'package')
              AND group_parent.parent_product_id IN (%s)
            ORDER BY
                group_parent.parent_product_id ASC,
                COALESCE(pg.group_order, 0) ASC,
                pg.id ASC,
                pgp.id ASC,
                child.product ASC,
                child.id ASC
        SQL;

        $sql = sprintf(
            $sql,
            'pgp.product_group_id = pg.id',
            implode(', ', $placeholders)
        );

        return $connection->fetchAllAssociative($sql, $params);
    }

    private function attachCatalogModifierGroups(People $provider, array $products): array
    {
        if (empty($products)) {
            return [];
        }

        $productIds = array_map(
            static fn(array $product) => (int) ($product['id'] ?? 0),
            $products
        );
        $modifierRows = $this->fetchCatalogModifierRows($provider, $productIds);
        if (empty($modifierRows)) {
            return array_map(static function (array $product): array {
                $product['modifier_groups'] = [];
                return $product;
            }, $products);
        }

        $groupsByParentProduct = [];
        foreach ($modifierRows as $modifierRow) {
            $parentProductId = (int) ($modifierRow['parent_product_id'] ?? 0);
            $groupId = (int) ($modifierRow['product_group_id'] ?? 0);
            if ($parentProductId <= 0 || $groupId <= 0) {
                continue;
            }

            if (!isset($groupsByParentProduct[$parentProductId][$groupId])) {
                $isRequired = (int) ($modifierRow['group_required'] ?? 0) === 1;
                $minimum = max(0, (int) round((float) ($modifierRow['group_minimum'] ?? 0)));
                $maximum = max(0, (int) round((float) ($modifierRow['group_maximum'] ?? 0)));

                if ($isRequired && $minimum === 0) {
                    $minimum = 1;
                }

                $groupsByParentProduct[$parentProductId][$groupId] = [
                    'id' => $groupId,
                    'name' => trim((string) ($modifierRow['product_group_name'] ?? 'Grupo')),
                    'required' => $isRequired,
                    'minimum' => $minimum,
                    'maximum' => $maximum,
                    'group_order' => max(0, (int) ($modifierRow['group_order'] ?? 0)),
                    'active' => (int) ($modifierRow['group_active'] ?? 0) === 1,
                    'options' => [],
                ];
            }

            $relationId = (int) ($modifierRow['product_group_product_id'] ?? 0);
            $childProductId = (int) ($modifierRow['child_product_id'] ?? 0);
            $childName = trim((string) ($modifierRow['child_product_name'] ?? ''));
            if ($relationId <= 0 || $childProductId <= 0 || $childName === '') {
                continue;
            }

            $rawChildPrice = $modifierRow['child_relation_price'] ?? null;
            $childPrice = ($rawChildPrice === null || $rawChildPrice === '')
                ? (float) ($modifierRow['child_base_price'] ?? 0)
                : (float) $rawChildPrice;

            $groupsByParentProduct[$parentProductId][$groupId]['options'][] = [
                'id' => $relationId,
                'child_product_id' => $childProductId,
                'name' => $childName,
                'description' => trim((string) ($modifierRow['child_description'] ?? '')),
                'sku' => trim((string) ($modifierRow['child_sku'] ?? '')),
                'cover_file_id' => $modifierRow['child_cover_file_id'] ?? null,
                'quantity' => (float) ($modifierRow['child_quantity'] ?? 0),
                'price' => round($childPrice, 2),
                'active' => (int) ($modifierRow['relation_active'] ?? 0) === 1
                    && (int) ($modifierRow['child_active'] ?? 0) === 1,
            ];
        }

        return array_map(static function (array $product) use ($groupsByParentProduct): array {
            $parentProductId = (int) ($product['id'] ?? 0);
            $modifierGroups = array_values($groupsByParentProduct[$parentProductId] ?? []);

            $modifierGroups = array_values(array_filter(array_map(static function (array $group): ?array {
                $optionCount = count($group['options'] ?? []);
                if ($optionCount === 0) {
                    return null;
                }

                $minimum = max(0, (int) ($group['minimum'] ?? 0));
                $maximum = max(0, (int) ($group['maximum'] ?? 0));

                if ($maximum <= 0) {
                    $maximum = $optionCount;
                }

                if ($minimum > $optionCount) {
                    $minimum = $optionCount;
                }

                if ($maximum > $optionCount) {
                    $maximum = $optionCount;
                }

                if ($maximum < $minimum) {
                    $maximum = $minimum;
                }

                $group['minimum'] = $minimum;
                $group['maximum'] = $maximum;
                return $group;
            }, $modifierGroups)));

            $product['modifier_groups'] = $modifierGroups;
            return $product;
        }, $products);
    }

    /* --- catálogo v2 helpers ------------------------------------------ */

    private function fetchIfoodDefaultCatalogId(string $merchantId): ?string
    {
        $token = $this->getAccessToken();
        if (!$token) return null;
        try {
            $response = $this->httpClient->request('GET',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/catalogs',
                ['headers' => ['Authorization' => 'Bearer ' . $token]]
            );
            if ($response->getStatusCode() !== 200) return null;
            $catalogs = $response->toArray(false);
            if (!is_array($catalogs) || empty($catalogs)) return null;
            $id = $this->normalizeString($catalogs[0]['catalogId'] ?? $catalogs[0]['id'] ?? null);
            return $id !== '' ? $id : null;
        } catch (\Throwable $e) {
            self::$logger->error('iFood catalog v2 list failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function fetchIfoodCatalogItemsV2(string $merchantId): array
    {
        $token = $this->getAccessToken();
        if (!$token) return [];
        try {
            $catalogId = $this->fetchIfoodDefaultCatalogId($merchantId);
            if ($catalogId === null) return [];

            $response = $this->httpClient->request('GET',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/catalogs/' . rawurlencode($catalogId) . '/categories',
                [
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                    'query'   => ['includeItems' => 'true'],
                ]
            );
            if ($response->getStatusCode() !== 200) return [];
            $categories = $response->toArray(false);
            if (!is_array($categories)) return [];

            $allItems = [];
            foreach ($categories as $cat) {
                if (!is_array($cat['items'] ?? null)) continue;
                foreach ($cat['items'] as $item) {
                    $item['_categoryId']   = $cat['id']   ?? null;
                    $item['_categoryName'] = $cat['name'] ?? null;
                    $allItems[]            = $item;
                }
            }
            return $allItems;
        } catch (\Throwable $e) {
            self::$logger->error('iFood catalog v2 items fetch failed', ['merchant_id' => $merchantId, 'error' => $e->getMessage()]);
            return [];
        }
    }

    private function fetchIfoodCatalogItemFlatV2(string $merchantId, string $itemId): ?array
    {
        $token = $this->getAccessToken();
        $normalizedItemId = $this->normalizeString($itemId);
        if (!$token || $normalizedItemId === '') return null;

        try {
            $response = $this->httpClient->request('GET',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/items/' . rawurlencode($normalizedItemId) . '/flat',
                ['headers' => ['Authorization' => 'Bearer ' . $token]]
            );

            if ($response->getStatusCode() !== 200) return null;
            $item = $response->toArray(false);

            return is_array($item) ? $item : null;
        } catch (\Throwable $e) {
            self::$logger->warning('iFood catalog v2 flat item fetch failed', [
                'merchant_id' => $merchantId,
                'item_id' => $normalizedItemId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function buildIfoodCatalogItemExternalCodeIndex(string $merchantId, array $items, bool $includeFlatRootProduct = false): array
    {
        $remoteByEc = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemId = $this->normalizeString($item['id'] ?? null);
            if ($itemId === '') {
                continue;
            }

            $status = $this->normalizeString($item['status'] ?? 'AVAILABLE');
            $itemProductId = $this->normalizeString($item['productId'] ?? null);
            $categoryId = $this->normalizeString($item['_categoryId'] ?? null);
            $this->registerIfoodCatalogExternalCode(
                $remoteByEc,
                $item['externalCode'] ?? null,
                $itemId,
                $status,
                'item',
                $itemProductId,
                $categoryId
            );

            if (!$includeFlatRootProduct) {
                continue;
            }

            $itemExternalCode = $this->normalizeString($item['externalCode'] ?? null);
            if ($itemExternalCode !== '' && ctype_digit($itemExternalCode)) {
                continue;
            }

            $flat = $this->fetchIfoodCatalogItemFlatV2($merchantId, $itemId);
            if (!is_array($flat)) {
                continue;
            }

            $flatItem = is_array($flat['item'] ?? null) ? $flat['item'] : $flat;
            $rootProductId = $itemProductId !== '' ? $itemProductId : $this->normalizeString($flatItem['productId'] ?? null);
            $this->registerIfoodCatalogExternalCode(
                $remoteByEc,
                $flatItem['externalCode'] ?? null,
                $itemId,
                $status,
                'item',
                $rootProductId,
                $categoryId
            );

            $products = is_array($flat['products'] ?? null) ? $flat['products'] : [];
            $optionsByProductId = $this->mapIfoodFlatOptionsByProductId($flat);

            foreach ($products as $product) {
                if (!is_array($product)) {
                    continue;
                }

                $productId = $this->normalizeString($product['id'] ?? null);
                if ($rootProductId !== '' && $productId !== $rootProductId) {
                    continue;
                }
                if ($rootProductId === '' && count($products) !== 1) {
                    continue;
                }

                $this->registerIfoodCatalogExternalCode(
                    $remoteByEc,
                    $product['externalCode'] ?? null,
                    $itemId,
                    $status,
                    'root_product',
                    $rootProductId !== '' ? $rootProductId : $productId,
                    $categoryId
                );
            }

            foreach ($products as $product) {
                if (!is_array($product)) {
                    continue;
                }

                $productId = $this->normalizeString($product['id'] ?? null);
                if ($productId === '' || $productId === $rootProductId || empty($optionsByProductId[$productId])) {
                    continue;
                }

                foreach ($optionsByProductId[$productId] as $option) {
                    $optionId = $this->normalizeString($option['id'] ?? null);
                    if ($optionId === '') {
                        continue;
                    }

                    $optionStatus = $this->normalizeString($option['status'] ?? $status);
                    $this->registerIfoodCatalogExternalCode(
                        $remoteByEc,
                        $product['externalCode'] ?? null,
                        $itemId,
                        $optionStatus !== '' ? $optionStatus : $status,
                        'option_product',
                        $productId,
                        $categoryId,
                        $optionId
                    );
                    $this->registerIfoodCatalogExternalCode(
                        $remoteByEc,
                        $option['externalCode'] ?? null,
                        $itemId,
                        $optionStatus !== '' ? $optionStatus : $status,
                        'option',
                        $productId,
                        $categoryId,
                        $optionId
                    );
                }
            }
        }

        return $remoteByEc;
    }

    private function mapIfoodFlatOptionsByProductId(array $flat): array
    {
        $mapped = [];
        $options = is_array($flat['options'] ?? null) ? $flat['options'] : [];

        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $productId = $this->normalizeString($option['productId'] ?? null);
            if ($productId === '') {
                continue;
            }

            $mapped[$productId][] = $option;
        }

        return $mapped;
    }

    private function registerIfoodCatalogExternalCode(
        array &$remoteByEc,
        mixed $externalCode,
        string $itemId,
        string $status,
        string $source,
        string $productId = '',
        string $categoryId = '',
        string $optionId = ''
    ): void
    {
        $candidates = $this->normalizeIfoodCatalogExternalCodeCandidates($externalCode);
        if (empty($candidates) || $itemId === '') {
            return;
        }

        $priority = 0;
        if ($source === 'item') {
            $priority = 3;
        } elseif ($source === 'root_product') {
            $priority = 2;
        } elseif ($source === 'option') {
            $priority = 1;
        }

        foreach ($candidates as $ec) {
            $currentPriority = (int) ($remoteByEc[$ec]['match_priority'] ?? -1);
            if ($currentPriority > $priority) {
                continue;
            }

            $remoteByEc[$ec] = [
                'item_id' => $itemId,
                'product_id' => $productId,
                'category_id' => $categoryId,
                'option_id' => $optionId !== '' ? $optionId : null,
                'status'  => $status !== '' ? $status : 'AVAILABLE',
                'match_source' => $source,
                'match_priority' => $priority,
            ];
        }
    }

    private function normalizeIfoodCatalogExternalCodeCandidates(mixed $externalCode): array
    {
        $ec = $this->normalizeString($externalCode);
        if ($ec === '') {
            return [];
        }

        $candidates = [$ec];
        if (preg_match('/^option-product-(\d+)$/', $ec, $matches)) {
            $candidates[] = $matches[1];
        }

        return array_values(array_unique($candidates));
    }

    private function getOrCreateDefaultCatalogCategory(string $merchantId, string $catalogId): ?string
    {
        return $this->resolveIfoodCatalogCategoryId($merchantId, $catalogId, 'Produtos', [], 0);
    }

    private function fetchIfoodCatalogCategoriesV2(string $merchantId, string $catalogId): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return [];
        }

        try {
            $response = $this->httpClient->request('GET',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/catalogs/' . rawurlencode($catalogId) . '/categories',
                ['headers' => ['Authorization' => 'Bearer ' . $token]]
            );
            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $cats = $response->toArray(false);
            return is_array($cats) ? $cats : [];
        } catch (\Throwable $e) {
            self::$logger->error('iFood catalog v2 categories fetch failed', [
                'merchant_id' => $merchantId,
                'catalog_id' => $catalogId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function normalizeText(string $text): string
    {
        $lower = mb_strtolower(trim($text));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        return is_string($ascii) ? trim($ascii) : $lower;
    }

    private function patchIfoodCatalogCategoryV2(
        string $merchantId,
        string $catalogId,
        string $categoryId,
        array $categoryBody,
        string $token
    ): array {
        try {
            $response = $this->httpClient->request('PATCH',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/catalogs/' . rawurlencode($catalogId) . '/categories/' . rawurlencode($categoryId),
                [
                    'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                    'json'    => $categoryBody,
                ]
            );

            $statusCode = $response->getStatusCode();
            $body = $statusCode >= 200 && $statusCode < 300 ? '' : substr($response->getContent(false), 0, 2000);

            return [
                'ok' => $statusCode >= 200 && $statusCode < 300,
                'status' => $statusCode,
                'body' => $body,
                'error' => null,
                'not_found' => $this->isIfoodCatalogCategoryNotFound($statusCode, $body),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => null,
                'body' => null,
                'error' => $e->getMessage(),
                'not_found' => $this->isIfoodCatalogCategoryNotFound(null, null, $e->getMessage()),
            ];
        }
    }

    private function isIfoodCatalogCategoryNotFound(?int $status, ?string $body = null, ?string $error = null): bool
    {
        if ($status === 404) {
            return true;
        }

        $message = strtolower((string) ($body ?: $error));
        return str_contains($message, 'notfound') || str_contains($message, 'not found');
    }

    private function resolveIfoodCatalogCategoryId(
        string $merchantId,
        string $catalogId,
        string $categoryName,
        array &$remoteCategoriesByName,
        int $sequence = 0,
        int $localCategoryId = 0,
        string $storedIfoodId = ''
    ): ?string {
        $token = $this->getAccessToken();
        if (!$token) {
            return null;
        }

        $effectiveName  = trim($categoryName) !== '' ? trim($categoryName) : 'Produtos';
        $normalizedName = $this->normalizeText($effectiveName);
        $categoryBody   = [
            'name'     => $effectiveName,
            'status'   => 'AVAILABLE',
            'template' => 'DEFAULT',
            'sequence' => max(0, $sequence),
        ];
        $knownRemoteCategoryIds = array_values(array_unique(array_filter(array_map(
            fn($id) => $this->normalizeString($id),
            array_values($remoteCategoriesByName)
        ))));

        // Prioridade 1: ID remoto ja armazenado localmente — atualiza via PATCH
        if ($storedIfoodId !== '') {
            $storedIdKnownRemotely = in_array($storedIfoodId, $knownRemoteCategoryIds, true);
            if ($storedIdKnownRemotely || empty($knownRemoteCategoryIds)) {
                $patchResult = $this->patchIfoodCatalogCategoryV2($merchantId, $catalogId, $storedIfoodId, $categoryBody, $token);
                if ($patchResult['ok']) {
                    $remoteCategoriesByName[$normalizedName] = $storedIfoodId;
                    return $storedIfoodId;
                }

                self::$logger->warning('iFood PATCH category failed', [
                    'merchant_id'       => $merchantId,
                    'catalog_id'        => $catalogId,
                    'ifood_category_id' => $storedIfoodId,
                    'local_category_id' => $localCategoryId,
                    'category_name'     => $effectiveName,
                    'status_code'       => $patchResult['status'],
                    'body'              => $patchResult['body'],
                    'error'             => $patchResult['error'],
                ]);

                if (!$patchResult['not_found']) {
                    return $storedIfoodId;
                }
            } else {
                self::$logger->warning('iFood stored category id not found in remote category list', [
                    'merchant_id'       => $merchantId,
                    'catalog_id'        => $catalogId,
                    'ifood_category_id' => $storedIfoodId,
                    'local_category_id' => $localCategoryId,
                    'category_name'     => $effectiveName,
                ]);
            }
        }

        // Prioridade 2: Sem ID armazenado — busca por nome para evitar duplicatas
        if ($normalizedName !== '' && isset($remoteCategoriesByName[$normalizedName])) {
            $existingId = $this->normalizeString($remoteCategoriesByName[$normalizedName]);
            // Persiste vinculo local → remoto para proximas publicacoes
            if ($existingId !== '') {
                $patchResult = $this->patchIfoodCatalogCategoryV2($merchantId, $catalogId, $existingId, $categoryBody, $token);
                if ($patchResult['ok'] || !$patchResult['not_found']) {
                    $this->persistIfoodCategoryCode($localCategoryId, $existingId);
                    return $existingId;
                }

                self::$logger->warning('iFood PATCH category (por nome) failed', [
                    'merchant_id'        => $merchantId,
                    'catalog_id'         => $catalogId,
                    'ifood_category_id'  => $existingId,
                    'local_category_id'  => $localCategoryId,
                    'category_name'      => $effectiveName,
                    'status_code'        => $patchResult['status'],
                    'body'               => $patchResult['body'],
                    'error'              => $patchResult['error'],
                ]);
                unset($remoteCategoriesByName[$normalizedName]);
            }
        }

        // Prioridade 3: Categoria nova — cria via POST e armazena vinculo
        try {
            $response = $this->httpClient->request('POST',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/catalogs/' . rawurlencode($catalogId) . '/categories',
                [
                    'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                    'json'    => $categoryBody,
                ]
            );

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                self::$logger->error('iFood create category failed', [
                    'merchant_id'       => $merchantId,
                    'catalog_id'        => $catalogId,
                    'local_category_id' => $localCategoryId,
                    'category_name'     => $effectiveName,
                    'status_code'       => $statusCode,
                    'body'              => substr($response->getContent(false), 0, 2000),
                ]);
                return null;
            }

            $data = $response->toArray(false);
            $id   = $this->normalizeString($data['id'] ?? null);
            if ($id !== '') {
                $remoteCategoriesByName[$normalizedName] = $id;
                $this->persistIfoodCategoryCode($localCategoryId, $id);
                return $id;
            }
        } catch (\Throwable $e) {
            self::$logger->error('iFood create category failed', [
                'merchant_id'       => $merchantId,
                'catalog_id'        => $catalogId,
                'local_category_id' => $localCategoryId,
                'category_name'     => $effectiveName,
                'error'             => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function persistIfoodCategoryCode(int $localCategoryId, string $ifoodCategoryId): void
    {
        if ($localCategoryId <= 0 || $ifoodCategoryId === '') {
            return;
        }
        try {
            $category = $this->entityManager->getRepository(Category::class)->find($localCategoryId);
            if ($category instanceof Category) {
                $this->discoveryFoodCode($category, $ifoodCategoryId);
                $this->entityManager->flush();
            }
        } catch (\Throwable $e) {
            self::$logger->warning('iFood persistIfoodCategoryCode failed', [
                'local_category_id'  => $localCategoryId,
                'ifood_category_id'  => $ifoodCategoryId,
                'error'              => $e->getMessage(),
            ]);
        }
    }

    private function generateUuidV4(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    private function generateStableUuidFromSeed(string $seed): string
    {
        $hash = md5(self::APP_CONTEXT . '|' . $seed);
        $timeHiAndVersion = (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x4000;
        $clockSeq = (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000;

        return sprintf(
            '%s-%s-%04x-%04x-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            $timeHiAndVersion,
            $clockSeq,
            substr($hash, 20, 12)
        );
    }

    private function mapIfoodOptionIdsByGroupProduct(?array $existingItemFlat): array
    {
        if (!is_array($existingItemFlat)) {
            return [];
        }

        $optionsById = [];
        $existingOptions = is_array($existingItemFlat['options'] ?? null) ? $existingItemFlat['options'] : [];
        foreach ($existingOptions as $option) {
            if (!is_array($option)) {
                continue;
            }

            $optionId = $this->normalizeString($option['id'] ?? null);
            if ($optionId !== '') {
                $optionsById[$optionId] = $option;
            }
        }

        $mapped = [];
        $existingGroups = is_array($existingItemFlat['optionGroups'] ?? null) ? $existingItemFlat['optionGroups'] : [];
        foreach ($existingGroups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $groupId = $this->normalizeString($group['id'] ?? null);
            $optionIds = is_array($group['optionIds'] ?? null) ? $group['optionIds'] : [];
            if ($groupId === '' || empty($optionIds)) {
                continue;
            }

            foreach ($optionIds as $optionId) {
                $normalizedOptionId = $this->normalizeString($optionId);
                $option = $optionsById[$normalizedOptionId] ?? null;
                $productId = $this->normalizeString(is_array($option) ? ($option['productId'] ?? null) : null);
                if ($normalizedOptionId !== '' && $productId !== '') {
                    $mapped[$groupId . '|' . $productId] = $normalizedOptionId;
                }
            }
        }

        return $mapped;
    }

    private function buildIfoodCatalogModifierPayload(string $merchantId, array $product, ?array $existingItemFlat = null): array
    {
        $modifierGroups = is_array($product['modifier_groups'] ?? null) ? $product['modifier_groups'] : [];
        if (empty($modifierGroups)) {
            return [
                'product_option_groups' => null,
                'products' => [],
                'option_groups' => [],
                'options' => [],
            ];
        }

        $productOptionGroups = [];
        $productsById = [];
        $optionGroups = [];
        $options = [];
        $existingOptionIds = $this->mapIfoodOptionIdsByGroupProduct($existingItemFlat);

        foreach ($modifierGroups as $groupIndex => $group) {
            $groupId = (int) ($group['id'] ?? 0);
            $groupName = trim((string) ($group['name'] ?? ''));
            $groupOptions = is_array($group['options'] ?? null) ? $group['options'] : [];
            if ($groupId <= 0 || $groupName === '' || empty($groupOptions)) {
                continue;
            }

            $groupUuid = $this->generateStableUuidFromSeed('catalog:group:' . $groupId);
            $optionIds = [];

            foreach ($groupOptions as $optionIndex => $option) {
                $relationId = (int) ($option['id'] ?? 0);
                $childProductId = (int) ($option['child_product_id'] ?? 0);
                $childName = trim((string) ($option['name'] ?? ''));
                if ($relationId <= 0 || $childProductId <= 0 || $childName === '') {
                    continue;
                }

                $childProductUuid = $this->generateStableUuidFromSeed('catalog:option-product:' . $childProductId);
                if (!isset($productsById[$childProductUuid])) {
                    $childProductBody = [
                        'id' => $childProductUuid,
                        'externalCode' => 'option-product-' . $childProductId,
                        'name' => $childName,
                        'description' => (string) ($option['description'] ?? ''),
                        'serving' => 'SERVES_1',
                        'optionGroups' => null,
                    ];

                    $childSku = trim((string) ($option['sku'] ?? ''));
                    if ($childSku !== '') {
                        $childProductBody['ean'] = $childSku;
                    }

                    $childQuantity = (float) ($option['quantity'] ?? 0);
                    if ($childQuantity > 0) {
                        $childProductBody['quantity'] = $childQuantity;
                    }

                    $childCoverFileId = $option['cover_file_id'] ?? null;
                    $childSourceImageUrl = $this->buildPublicFileDownloadUrl($childCoverFileId);
                    if ($childSourceImageUrl) {
                        $uploadedChildImagePath = $this->uploadIfoodCatalogImageAndResolvePath($merchantId, $childCoverFileId, $childSourceImageUrl);
                        if ($uploadedChildImagePath) {
                            $childProductBody['imagePath'] = $uploadedChildImagePath;
                        } else {
                            self::$logger->warning('iFood catalog child image upload skipped, proceeding without imagePath', [
                                'merchant_id' => $merchantId,
                                'product_id' => $product['id'] ?? null,
                                'child_product_id' => $childProductId,
                                'image_url' => $childSourceImageUrl,
                            ]);
                        }
                    }

                    $productsById[$childProductUuid] = $childProductBody;
                }

                $optionMapKey = $groupUuid . '|' . $childProductUuid;
                $optionSeed = is_array($existingItemFlat)
                    ? 'catalog:option:' . $groupId . ':' . $childProductId
                    : 'catalog:option:' . $relationId;
                $optionUuid = $existingOptionIds[$optionMapKey]
                    ?? $this->generateStableUuidFromSeed($optionSeed);
                $optionIds[] = $optionUuid;
                $optionPrice = round((float) ($option['price'] ?? 0), 2);

                $options[] = [
                    'id' => $optionUuid,
                    'status' => !empty($option['active']) ? 'AVAILABLE' : 'UNAVAILABLE',
                    'index' => $optionIndex,
                    'productId' => $childProductUuid,
                    'price' => [
                        'value' => $optionPrice,
                        'originalValue' => $optionPrice,
                    ],
                    'externalCode' => 'option-' . $relationId,
                ];
            }

            if (empty($optionIds)) {
                continue;
            }

            $productOptionGroups[] = [
                'id' => $groupUuid,
                'min' => max(0, (int) ($group['minimum'] ?? 0)),
                'max' => max(0, (int) ($group['maximum'] ?? 0)),
            ];

            $optionGroups[] = [
                'id' => $groupUuid,
                'name' => $groupName,
                'externalCode' => 'option-group-' . $groupId,
                'status' => !empty($group['active']) ? 'AVAILABLE' : 'UNAVAILABLE',
                'index' => max(0, (int) ($group['group_order'] ?? $groupIndex)),
                'optionGroupType' => 'DEFAULT',
                'optionIds' => $optionIds,
            ];
        }

        return [
            'product_option_groups' => !empty($productOptionGroups) ? $productOptionGroups : null,
            'products' => array_values($productsById),
            'option_groups' => $optionGroups,
            'options' => $options,
        ];
    }

    private function normalizeImageMimeType(?string $contentType): ?string
    {
        $normalized = strtolower(trim((string) $contentType));
        if ($normalized === '') {
            return null;
        }

        $normalized = explode(';', $normalized)[0] ?? $normalized;
        $normalized = trim($normalized);

        if ($normalized === 'image/jpg' || $normalized === 'image/pjpeg') {
            return 'image/jpeg';
        }

        if ($normalized === 'image/x-png') {
            return 'image/png';
        }

        return in_array($normalized, ['image/jpeg', 'image/png'], true) ? $normalized : null;
    }

    private function detectIfoodImageMimeType(string $binary, ?string $declaredMimeType): ?string
    {
        $detectedMimeType = null;

        if ($binary !== '' && class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->buffer($binary);
            $detectedMimeType = is_string($detected) ? $detected : null;
        }

        if (!$detectedMimeType && function_exists('getimagesizefromstring')) {
            $info = @getimagesizefromstring($binary);
            $detectedMimeType = is_array($info) ? ($info['mime'] ?? null) : null;
        }

        $normalizedDetectedMimeType = $this->normalizeImageMimeType($detectedMimeType);
        if ($normalizedDetectedMimeType) {
            return $normalizedDetectedMimeType;
        }

        $detectedMimeType = strtolower(trim((string) $detectedMimeType));
        if ($detectedMimeType !== '' && $detectedMimeType !== 'application/octet-stream') {
            return null;
        }

        return $this->normalizeImageMimeType($declaredMimeType);
    }

    private function buildIfoodImageDataUri(string $binary, string $mimeType): string
    {
        return 'data:' . $mimeType . ';base64,' . base64_encode($binary);
    }

    private function isIfoodUploadImageWithinLimits(string $binary, string $mimeType): bool
    {
        $sizeBytes = strlen($binary);
        if ($sizeBytes <= 0 || $sizeBytes > self::MAX_IMAGE_UPLOAD_BYTES) {
            return false;
        }

        $dataUriBytes = strlen('data:' . $mimeType . ';base64,') + (4 * (int) ceil($sizeBytes / 3));

        return ($dataUriBytes + self::IMAGE_UPLOAD_PAYLOAD_MARGIN_BYTES) <= self::MAX_IMAGE_UPLOAD_BYTES;
    }

    private function resizeIfoodGdImageToUploadCanvas(mixed $image): ?\GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        $scale = min(1, self::IMAGE_UPLOAD_MAX_DIMENSION / $width, self::IMAGE_UPLOAD_MAX_DIMENSION / $height);
        $targetWidth = (int) max(1, floor($width * $scale));
        $targetHeight = (int) max(1, floor($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$canvas) {
            return null;
        }

        imagealphablending($canvas, true);
        imagesavealpha($canvas, false);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $canvas;
    }

    private function encodeIfoodGdImageAsJpeg(mixed $image): ?string
    {
        $canvas = $this->resizeIfoodGdImageToUploadCanvas($image);
        if (!$canvas) {
            return null;
        }

        $jpeg = null;
        for ($quality = 90; $quality >= 60; $quality -= 5) {
            ob_start();
            $saved = imagejpeg($canvas, null, $quality);
            $candidate = ob_get_clean();
            if ($saved && is_string($candidate) && $this->isIfoodUploadImageWithinLimits($candidate, 'image/jpeg')) {
                $jpeg = $candidate;
                break;
            }
        }

        imagedestroy($canvas);

        return $jpeg;
    }

    private function convertIfoodImageToJpegWithGd(string $binary): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
            return null;
        }

        $image = @imagecreatefromstring($binary);
        if (!$image) {
            return null;
        }

        $jpeg = $this->encodeIfoodGdImageAsJpeg($image);
        imagedestroy($image);

        return $jpeg;
    }

    private function convertIfoodImageToJpegWithImagick(string $binary): ?string
    {
        if (!class_exists('Imagick')) {
            return null;
        }

        try {
            $imagick = new \Imagick();
            $imagick->setResolution(150, 150);
            $imagick->readImageBlob($binary);
            $imagick->setImageBackgroundColor(new \ImagickPixel('white'));

            if (defined('Imagick::LAYERMETHOD_FLATTEN')) {
                $flattened = $imagick->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                if ($flattened instanceof \Imagick) {
                    $imagick->clear();
                    $imagick = $flattened;
                }
            }

            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();
            if ($width <= 0 || $height <= 0) {
                $imagick->clear();
                return null;
            }

            $scale = min(1, self::IMAGE_UPLOAD_MAX_DIMENSION / $width, self::IMAGE_UPLOAD_MAX_DIMENSION / $height);
            if ($scale < 1) {
                $imagick->resizeImage(
                    (int) max(1, floor($width * $scale)),
                    (int) max(1, floor($height * $scale)),
                    \Imagick::FILTER_LANCZOS,
                    1
                );
            }

            $imagick->setImageFormat('jpeg');
            $jpeg = null;
            for ($quality = 90; $quality >= 60; $quality -= 5) {
                $imagick->setImageCompressionQuality($quality);
                $candidate = $imagick->getImageBlob();
                if (is_string($candidate) && $this->isIfoodUploadImageWithinLimits($candidate, 'image/jpeg')) {
                    $jpeg = $candidate;
                    break;
                }
            }

            $imagick->clear();

            return $jpeg;
        } catch (\Throwable) {
            return null;
        }
    }

    private function convertIfoodImageToJpeg(string $binary): ?string
    {
        return $this->convertIfoodImageToJpegWithGd($binary)
            ?: $this->convertIfoodImageToJpegWithImagick($binary);
    }

    private function buildIfoodUploadImageDataUriFromBinary(string $binary, ?string $contentType, array $logContext): ?string
    {
        $mimeType = $this->detectIfoodImageMimeType($binary, $contentType);
        if ($mimeType && $this->isIfoodUploadImageWithinLimits($binary, $mimeType)) {
            return $this->buildIfoodImageDataUri($binary, $mimeType);
        }

        $jpeg = $this->convertIfoodImageToJpeg($binary);
        if ($jpeg && $this->isIfoodUploadImageWithinLimits($jpeg, 'image/jpeg')) {
            return $this->buildIfoodImageDataUri($jpeg, 'image/jpeg');
        }

        if (self::$logger) {
            self::$logger->warning('iFood image could not be normalized to upload requirements', $logContext + [
                'content_type' => $contentType,
                'detected_mime_type' => $mimeType,
                'size_bytes' => strlen($binary),
                'max_request_bytes' => self::MAX_IMAGE_UPLOAD_BYTES,
            ]);
        }

        return null;
    }

    private function fetchIfoodLocalImageData(mixed $fileId): ?array
    {
        if ($fileId === null || $fileId === '') {
            return null;
        }

        $normalizedFileId = preg_replace('/\D+/', '', (string) $fileId);
        if ($normalizedFileId === '') {
            return null;
        }

        $file = $this->entityManager->getRepository(File::class)->find((int) $normalizedFileId);
        if (!$file instanceof File) {
            return null;
        }

        $fileType = trim((string) $file->getFileType());
        $extension = strtolower(ltrim(trim((string) $file->getExtension()), '.'));
        $contentType = $fileType !== '' && $extension !== '' ? $fileType . '/' . $extension : null;

        return [
            'binary' => $file->getContent(true),
            'content_type' => $contentType,
            'log_context' => [
                'file_id' => (int) $normalizedFileId,
                'source' => 'local_file',
            ],
        ];
    }

    private function fetchIfoodRemoteImageData(string $imageUrl): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $imageUrl);
            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                self::$logger->warning('iFood image fetch failed before upload', [
                    'image_url' => $imageUrl,
                    'status_code' => $statusCode,
                ]);
                return null;
            }

            $headers = $response->getHeaders(false);
            $contentType = $headers['content-type'][0] ?? null;
            $binary = $response->getContent(false);
            if ($binary === '') {
                self::$logger->warning('iFood image fetch returned invalid size for upload', [
                    'image_url' => $imageUrl,
                    'size_bytes' => 0,
                    'max_bytes' => self::MAX_IMAGE_UPLOAD_BYTES,
                ]);
                return null;
            }

            return [
                'binary' => $binary,
                'content_type' => $contentType,
                'log_context' => [
                    'image_url' => $imageUrl,
                    'source' => 'remote_url',
                ],
            ];
        } catch (\Throwable $e) {
            self::$logger->warning('iFood image fetch exception before upload', [
                'image_url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function buildIfoodUploadImageDataUri(mixed $fileId, ?string $imageUrl = null): ?string
    {
        $imageData = $this->fetchIfoodLocalImageData($fileId);
        if (!$imageData && $imageUrl) {
            $imageData = $this->fetchIfoodRemoteImageData($imageUrl);
        }

        if (!$imageData) {
            return null;
        }

        return $this->buildIfoodUploadImageDataUriFromBinary(
            (string) ($imageData['binary'] ?? ''),
            $imageData['content_type'] ?? null,
            is_array($imageData['log_context'] ?? null) ? $imageData['log_context'] : []
        );
    }

    private function resolveIfoodUploadedImagePath(mixed $responseBody): ?string
    {
        if (!is_array($responseBody)) {
            return null;
        }

        $candidates = [
            $responseBody['imagePath'] ?? null,
            $responseBody['path'] ?? null,
            $responseBody['url'] ?? null,
            is_array($responseBody['data'] ?? null) ? ($responseBody['data']['imagePath'] ?? null) : null,
            is_array($responseBody['data'] ?? null) ? ($responseBody['data']['path'] ?? null) : null,
            is_array($responseBody['data'] ?? null) ? ($responseBody['data']['url'] ?? null) : null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeString($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function uploadIfoodCatalogImageAndResolvePath(string $merchantId, mixed $fileId, string $sourceImageUrl): ?string
    {
        $cacheKey = $merchantId . '|' . $sourceImageUrl;
        if (array_key_exists($cacheKey, self::$catalogImagePathCache)) {
            return self::$catalogImagePathCache[$cacheKey] ?: null;
        }

        $token = $this->getAccessToken();
        if (!$token) {
            self::$catalogImagePathCache[$cacheKey] = '';
            return null;
        }

        $dataUri = $this->buildIfoodUploadImageDataUri($fileId, $sourceImageUrl);
        if (!$dataUri) {
            self::$catalogImagePathCache[$cacheKey] = '';
            return null;
        }

        try {
            $response = $this->httpClient->request('POST',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/image/upload',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'image' => $dataUri,
                    ],
                ]
            );

            $statusCode = $response->getStatusCode();
            $body = $response->toArray(false);
            $imagePath = $this->resolveIfoodUploadedImagePath($body);

            if ($statusCode >= 200 && $statusCode < 300 && $imagePath) {
                self::$catalogImagePathCache[$cacheKey] = $imagePath;
                return $imagePath;
            }

            self::$logger->warning('iFood catalog image upload failed', [
                'merchant_id' => $merchantId,
                'image_url' => $sourceImageUrl,
                'status_code' => $statusCode,
                'response' => is_array($body) ? $body : [],
            ]);
        } catch (\Throwable $e) {
            self::$logger->warning('iFood catalog image upload exception', [
                'merchant_id' => $merchantId,
                'image_url' => $sourceImageUrl,
                'error' => $e->getMessage(),
            ]);
        }

        self::$catalogImagePathCache[$cacheKey] = '';
        return null;
    }

    private function isIfoodCatalogConcurrentModification(?int $status, ?string $body, ?string $error = null): bool
    {
        if ($status !== 400) {
            return false;
        }

        $message = strtolower((string) ($body ?: $error));
        return str_contains($message, 'concurrently modified');
    }

    private function upsertIfoodCatalogItemV2(string $merchantId, array $product, ?array $existing, string $categoryId, ?array $existingItemFlat = null): array
    {
        $token = $this->getAccessToken();
        if (!$token) return ['ok' => false, 'http_status' => null, 'ifood_body' => null, 'error' => 'Token indisponivel'];

        $ec                = (string) $product['id'];
        $existingItemId    = $this->normalizeString($existing['item_id']    ?? null);
        $existingProductId = $this->normalizeString($existing['product_id'] ?? null);
        $usedCategoryId    = $this->normalizeString($categoryId) !== ''
            ? $categoryId
            : $this->normalizeString($existing['category_id'] ?? null);

        /* Para itens novos geramos UUIDs para ligar item ↔ produto */
        $productUuid = $existingProductId !== '' ? $existingProductId : $this->generateUuidV4();
        $itemUuid    = $existingItemId    !== '' ? $existingItemId    : $this->generateUuidV4();

        $itemBody = [
            'id'           => $itemUuid,
            'type'         => 'DEFAULT',
            'categoryId'   => $usedCategoryId,
            'status'       => (int) ($product['product_active'] ?? 1) === 1 ? 'AVAILABLE' : 'UNAVAILABLE',
            'price'        => [
                'value'         => (float) $product['price'],
                'originalValue' => (float) $product['price'],
            ],
            'externalCode' => $ec,
            'productId'    => $productUuid,
            'index'        => 0,
        ];

        $productBody = [
            'id'           => $productUuid,
            'name'         => $product['name'],
            'description'  => $product['description'] ?? '',
            'externalCode' => $ec,
            'serving'      => 'SERVES_1',
        ];
        $modifierPayload = $this->buildIfoodCatalogModifierPayload($merchantId, $product, $existingItemFlat);
        $productBody['optionGroups'] = $modifierPayload['product_option_groups'];
        $coverFileId = $product['cover_file_id'] ?? null;
        $sourceImageUrl = $this->buildPublicFileDownloadUrl($coverFileId);
        if ($sourceImageUrl) {
            $uploadedImagePath = $this->uploadIfoodCatalogImageAndResolvePath($merchantId, $coverFileId, $sourceImageUrl);
            if ($uploadedImagePath) {
                $productBody['imagePath'] = $uploadedImagePath;
            } else {
                self::$logger->warning('iFood catalog image upload skipped, proceeding without imagePath', [
                    'merchant_id' => $merchantId,
                    'product_id' => $product['id'] ?? null,
                    'image_url' => $sourceImageUrl,
                ]);
            }
        }

        $payload = [
            'item'         => $itemBody,
            'products'     => array_merge([$productBody], $modifierPayload['products']),
            'optionGroups' => $modifierPayload['option_groups'],
            'options'      => $modifierPayload['options'],
        ];

        $attempts = [$payload];
        if (isset($payload['products'][0]['imagePath'])) {
            $fallbackPayload = $payload;
            unset($fallbackPayload['products'][0]['imagePath']);
            $attempts[] = $fallbackPayload;
        }

        $lastStatus = null;
        $lastBody = '';
        $lastError = null;
        $lastPayload = null;

        foreach ($attempts as $attemptIndex => $attemptPayload) {
            foreach (array_merge([0], self::CATALOG_CONCURRENT_RETRY_DELAYS_US) as $retryIndex => $retryDelayUs) {
                if ($retryDelayUs > 0) {
                    usleep($retryDelayUs);
                }

                $lastPayload = $attemptPayload;
                try {
                    $response = $this->httpClient->request('PUT',
                        self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/items',
                        [
                            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                            'json'    => $attemptPayload,
                        ]
                    );
                    $status = $response->getStatusCode();
                    $body = substr($response->getContent(false), 0, 2000);
                    if ($status >= 200 && $status < 300) {
                        return ['ok' => true, 'http_status' => $status, 'ifood_body' => null, 'error' => null];
                    }

                    $lastStatus = $status;
                    $lastBody = $body;
                    $lastError = null;
                    $isConcurrentModification = $this->isIfoodCatalogConcurrentModification($status, $body);
                    if ($isConcurrentModification && $retryIndex < count(self::CATALOG_CONCURRENT_RETRY_DELAYS_US)) {
                        self::$logger->warning('iFood catalog v2 upsert concurrently modified, retrying same payload', [
                            'product_id' => $product['id'],
                            'status'     => $status,
                            'retry'      => $retryIndex + 1,
                        ]);
                        continue;
                    }

                    if (!$isConcurrentModification && $attemptIndex === 0 && count($attempts) > 1) {
                        self::$logger->warning('iFood catalog v2 upsert failed with imagePath, retrying without imagePath', [
                            'status'     => $status,
                            'product_id' => $product['id'],
                            'body'       => $body,
                        ]);
                        continue 2;
                    }
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                    if ($this->isIfoodCatalogConcurrentModification($lastStatus, $lastBody, $lastError) && $retryIndex < count(self::CATALOG_CONCURRENT_RETRY_DELAYS_US)) {
                        self::$logger->warning('iFood catalog v2 upsert concurrently modified exception, retrying same payload', [
                            'product_id' => $product['id'],
                            'retry'      => $retryIndex + 1,
                            'error'      => $e->getMessage(),
                        ]);
                        continue;
                    }

                    if ($attemptIndex === 0 && count($attempts) > 1) {
                        self::$logger->warning('iFood catalog v2 upsert exception with imagePath, retrying without imagePath', [
                            'product_id' => $product['id'],
                            'error' => $e->getMessage(),
                        ]);
                        continue 2;
                    }
                }

                break;
            }

            break;
        }

        if ($lastError !== null) {
            self::$logger->error('iFood catalog v2 upsert exception', [
                'product_id'   => $product['id'],
                'error'        => $lastError,
                'sent_payload' => $lastPayload,
            ]);
            return ['ok' => false, 'http_status' => null, 'ifood_body' => null, 'error' => $lastError, 'sent_payload' => $lastPayload];
        }

        self::$logger->warning('iFood catalog v2 upsert non-2xx', [
            'status'       => $lastStatus,
            'product_id'   => $product['id'],
            'body'         => $lastBody,
            'sent_payload' => $lastPayload,
        ]);
        return ['ok' => false, 'http_status' => $lastStatus, 'ifood_body' => $lastBody, 'error' => null, 'sent_payload' => $lastPayload];
    }

    public function getStoredIntegrationState(People $provider, bool $includeAuthCheck = false): array
    {
        $this->init();

        $providerId = (int) $provider->getId();
        $merchantId = $this->normalizeString(
            $this->getIfoodExtraDataValue('People', $providerId, 'code')
                ?? $this->getIfoodExtraDataValue('People', $providerId, 'merchant_id')
                ?? null
        );
        $merchantStatus = strtoupper($this->normalizeString(
            $this->getIfoodExtraDataValue('People', $providerId, 'merchant_status')
        ));
        $remoteConnectedRaw = $this->normalizeString(
            $this->getIfoodExtraDataValue('People', $providerId, 'remote_connected')
        );

        $connected = $merchantId !== '';
        $remoteConnected = $remoteConnectedRaw !== '' ? $remoteConnectedRaw === '1' : $connected;

        return [
            'connected' => $connected,
            'remote_connected' => $remoteConnected,
            'ifood_code' => $merchantId !== '' ? $merchantId : null,
            'merchant_id' => $merchantId !== '' ? $merchantId : null,
            'merchant_name' => $this->getIfoodExtraDataValue('People', $providerId, 'merchant_name'),
            'merchant_status' => $merchantStatus !== '' ? $merchantStatus : null,
            'merchant_status_label' => $this->normalizeMerchantStatusLabel($merchantStatus),
            'online' => in_array($merchantStatus, ['AVAILABLE', 'ONLINE', 'OPEN'], true),
            'connected_at' => $this->getIfoodExtraDataValue('People', $providerId, 'connected_at'),
            'disconnected_at' => $this->getIfoodExtraDataValue('People', $providerId, 'disconnected_at'),
            'last_sync_at' => $this->getIfoodExtraDataValue('People', $providerId, 'last_sync_at'),
            'last_error_code' => $this->getIfoodExtraDataValue('People', $providerId, 'last_error_code'),
            'last_error_message' => $this->getIfoodExtraDataValue('People', $providerId, 'last_error_message'),
            'auth_available' => $includeAuthCheck ? $this->isAuthAvailable() : null,
        ];
    }

    public function quoteDelivery(Order $order): array
    {
        $this->init();

        $provider = $order->getProvider();
        if (!$provider instanceof People) {
            return $this->persistIfoodQuoteFailure($order, 'unavailable', 'Pedido sem provider vinculado.');
        }

        $sourceOrder = $this->resolveIfoodQuoteSourceOrder($order);
        $stored = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($stored['merchant_id'] ?? null);
        if ($merchantId === '') {
            return $this->persistIfoodQuoteFailure($order, 'unavailable', 'Loja iFood nao conectada.');
        }

        self::$logger?->info('iFood quote delivery debug', [
            'quote_order_id' => $order->getId(),
            'source_order_id' => $sourceOrder->getId(),
            'quote_address_destination' => get_debug_type($order->getAddressDestination()),
            'source_address_destination' => get_debug_type($sourceOrder->getAddressDestination()),
        ]);

        $dropoffAddress = $this->resolveAddressCandidate($order->getAddressDestination());
        if (!$dropoffAddress instanceof Address) {
            $dropoffAddress = $this->resolveAddressCandidate($sourceOrder->getAddressDestination());
        }
        self::$logger?->info('iFood quote dropoff resolution', [
            'quote_order_id' => $order->getId(),
            'resolved_dropoff_type' => get_debug_type($dropoffAddress),
            'resolved_dropoff_id' => is_object($dropoffAddress) && method_exists($dropoffAddress, 'getId') ? $dropoffAddress->getId() : null,
        ]);
        $routeError = $this->validateIfoodQuoteRoute($pickupAddress, $dropoffAddress);
        if ($routeError !== null) {
            return $this->persistIfoodQuoteFailure($order, 'unavailable', $routeError);
        }
        if (!$dropoffAddress instanceof Address) {
            return $this->persistIfoodQuoteFailure($order, 'unavailable', 'Pedido sem endereco de entrega valido.');
        }

        $coordinates = $this->buildIfoodAddressCoordinatesPayload($dropoffAddress);
        if ($coordinates === []) {
            return $this->persistIfoodQuoteFailure($order, 'unavailable', 'Endereco de entrega sem coordenadas validas.');
        }

        $query = [
            'latitude' => $coordinates['latitude'],
            'longitude' => $coordinates['longitude'],
        ];
        $response = $this->callIfoodShippingMerchantAction(
            $merchantId,
            'GET',
            '/deliveryAvailabilities',
            $query
        );
        if (!$response || ($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            $statusCode = (int) ($response['status'] ?? 500);
            $quoteState = $this->resolveIfoodQuoteStateFromStatus($statusCode);
            $message = $this->normalizeString(
                $response['body']['message'] ?? $response['body']['error'] ?? $response['body']['description'] ?? 'Falha ao consultar cotacao do iFood.'
            );

            return $this->persistIfoodQuoteFailure($order, $quoteState, $message, [
                'merchant_id' => $merchantId,
                'quote_response' => $response['body'] ?? [],
                'quote_status' => $statusCode,
            ]);
        }

        $body = is_array($response['body'] ?? null) ? $response['body'] : [];
        $quoteId = $this->normalizeString($body['id'] ?? $body['quoteId'] ?? $body['quote_id'] ?? null);
        if ($quoteId === '') {
            return $this->persistIfoodQuoteFailure($order, 'error', 'iFood nao retornou identificador da cotacao.', [
                'merchant_id' => $merchantId,
                'quote_response' => $body,
            ]);
        }

        $quotePrice = $this->normalizeIfoodMoneyValue(
            $body['quote']['netValue']
                ?? $body['quote']['net_value']
                ?? $body['netValue']
                ?? $body['net_value']
                ?? null
        );
        if ($quotePrice === null || $quotePrice <= 0) {
            return $this->persistIfoodQuoteFailure($order, 'unavailable', 'iFood nao retornou valor de cotacao valido.', [
                'merchant_id' => $merchantId,
                'quote_response' => $body,
            ]);
        }
        $deliveryTime = $this->normalizeIfoodQuoteEta($body);
        $quoteRequestedAt = date('Y-m-d H:i:s');
        $logisticsState = [
            'flow' => 'quote',
            'provider_key' => 'ifood',
            'provider_label' => 'iFood',
            'quote_state' => 'ready',
            'quote_id' => $quoteId,
            'quote_requested_at' => $quoteRequestedAt,
            'quote_updated_at' => $quoteRequestedAt,
            'price' => $quotePrice,
            'eta' => $deliveryTime,
            'merchant_id' => $merchantId,
            'quote_response' => $body,
            'delivery_response' => null,
            'tracking_url' => null,
        ];
        $ifoodState = array_merge($this->getStoredIfoodQuoteState($order), [
            'quote_state' => 'ready',
            'quote_id' => $quoteId,
            'quote_requested_at' => $quoteRequestedAt,
            'quote_updated_at' => $quoteRequestedAt,
            'quote_response' => $body,
            'quote_message' => null,
            'merchant_id' => $merchantId,
        ]);
        $this->persistIfoodQuoteState($order, $ifoodState, $logisticsState);

        return [
            'errno' => 0,
            'errmsg' => 'ok',
            'data' => [
                'order_id' => $order->getId(),
                'quote_id' => $quoteId,
                'price' => $quotePrice,
                'eta' => $deliveryTime,
                'quote' => $body,
            ],
        ];
    }

    public function requestDeliveryFromQuote(Order $order): array
    {
        $this->init();

        $provider = $order->getProvider();
        if (!$provider instanceof People) {
            return [
                'errno' => 400,
                'errmsg' => 'Pedido sem provider vinculado.',
            ];
        }

        $sourceOrder = $this->resolveIfoodQuoteSourceOrder($order);
        $stored = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($stored['merchant_id'] ?? null);
        if ($merchantId === '') {
            return [
                'errno' => 422,
                'errmsg' => 'Loja iFood nao conectada.',
            ];
        }

        $logisticsState = $this->getStoredIfoodQuoteState($order);
        $quoteState = $this->normalizeString($logisticsState['quote_state'] ?? '');
        if (!in_array($quoteState, ['ready', 'selected', 'requested'], true)) {
            return [
                'errno' => 422,
                'errmsg' => 'Cotacao iFood nao esta pronta para solicitacao.',
            ];
        }

        $quoteResponse = is_array($logisticsState['quote_response'] ?? null) ? $logisticsState['quote_response'] : [];
        $quoteId = $this->normalizeString($logisticsState['quote_id'] ?? $quoteResponse['id'] ?? null);
        if ($quoteId === '') {
            return [
                'errno' => 422,
                'errmsg' => 'Cotacao iFood sem identificador valido.',
            ];
        }

        if ($this->normalizeString($logisticsState['remote_order_id'] ?? null) !== '') {
            return [
                'errno' => 0,
                'errmsg' => 'ok',
                'already_requested' => true,
                'data' => [
                    'order_id' => $order->getId(),
                    'remote_order_id' => $logisticsState['remote_order_id'],
                    'tracking_url' => $logisticsState['tracking_url'] ?? null,
                ],
            ];
        }

        $pickupAddress = $this->resolveAddressCandidate($order->getAddressOrigin());
        if (!$pickupAddress instanceof Address) {
            $pickupAddress = $this->resolveAddressCandidate($sourceOrder->getAddressOrigin());
        }
        if (!$pickupAddress instanceof Address) {
            return [
                'errno' => 422,
                'errmsg' => 'Pedido sem endereco de coleta valido.',
            ];
        }

        $dropoffAddress = $this->resolveAddressCandidate($order->getAddressDestination());
        if (!$dropoffAddress instanceof Address) {
            $dropoffAddress = $this->resolveAddressCandidate($sourceOrder->getAddressDestination());
        }
        if (!$dropoffAddress instanceof Address) {
            return [
                'errno' => 422,
                'errmsg' => 'Pedido sem endereco de entrega valido.',
            ];
        }

        $routeError = $this->validateIfoodQuoteRoute($pickupAddress, $dropoffAddress);
        if ($routeError !== null) {
            return [
                'errno' => 422,
                'errmsg' => $routeError,
            ];
        }

        $customerPayload = $this->buildIfoodQuoteCustomerPayload($sourceOrder);
        $deliveryPayload = $this->buildIfoodQuoteDeliveryPayload(
            $dropoffAddress,
            $quoteId,
            $quoteResponse,
            $logisticsState
        );
        $itemsPayload = $this->buildIfoodQuoteItemsPayload($sourceOrder);
        if ($itemsPayload === []) {
            return [
                'errno' => 422,
                'errmsg' => 'Pedido sem itens para criar entrega no iFood.',
            ];
        }

        $requestPayload = [
            'customer' => $customerPayload,
            'delivery' => $deliveryPayload,
            'items' => $itemsPayload,
            'metadata' => [
                'mainOrderId' => $sourceOrder->getId(),
                'quoteOrderId' => $order->getId(),
                'providerKey' => 'ifood',
            ],
        ];

        $response = $this->callIfoodShippingMerchantAction(
            $merchantId,
            'POST',
            '/orders',
            [],
            $requestPayload
        );
        if (!$response || ($response['status'] ?? 0) < 200 || ($response['status'] ?? 0) >= 300) {
            $statusCode = (int) ($response['status'] ?? 500);
            $quoteState = $this->resolveIfoodQuoteStateFromStatus($statusCode);
            $message = $this->normalizeString(
                $response['body']['message'] ?? $response['body']['error'] ?? $response['body']['description'] ?? 'Falha ao criar entrega no iFood.'
            );

            $this->persistIfoodQuoteState(
                $order,
                array_merge($logisticsState, [
                    'quote_state' => $quoteState,
                    'quote_message' => $message,
                    'quote_updated_at' => date('Y-m-d H:i:s'),
                    'delivery_response' => $response['body'] ?? [],
                ]),
                [
                    'flow' => 'quote',
                    'provider_key' => 'ifood',
                    'provider_label' => 'iFood',
                    'quote_state' => $quoteState,
                    'quote_id' => $quoteId,
                    'quote_message' => $message,
                    'quote_updated_at' => date('Y-m-d H:i:s'),
                    'quote_response' => $quoteResponse,
                    'delivery_response' => $response['body'] ?? [],
                ]
            );

            return [
                'errno' => $statusCode,
                'errmsg' => $message,
                'quote' => $quoteResponse,
                'request' => $response,
            ];
        }

        $body = is_array($response['body'] ?? null) ? $response['body'] : [];
        $remoteOrderId = $this->normalizeString($body['id'] ?? $body['orderId'] ?? null);
        $trackingUrl = $this->normalizeString($body['trackingUrl'] ?? $body['tracking_url'] ?? null);
        $selectedAt = date('Y-m-d H:i:s');
        $deliveryTime = $this->normalizeIfoodQuoteEta($quoteResponse);
        $quotePrice = $this->normalizeIfoodMoneyValue(
            $quoteResponse['quote']['netValue']
                ?? $quoteResponse['quote']['net_value']
                ?? $quoteResponse['netValue']
                ?? $quoteResponse['net_value']
                ?? null
        );
        if ($quotePrice === null || $quotePrice <= 0) {
            return $this->persistIfoodQuoteFailure($order, 'unavailable', 'iFood nao retornou valor de cotacao valido.', [
                'merchant_id' => $merchantId,
                'quote_response' => $quoteResponse,
            ]);
        }

        $ifoodState = array_merge($logisticsState, [
            'quote_state' => 'selected',
            'selected_at' => $selectedAt,
            'requested_at' => $selectedAt,
            'remote_order_id' => $remoteOrderId !== '' ? $remoteOrderId : null,
            'tracking_url' => $trackingUrl !== '' ? $trackingUrl : null,
            'delivery_response' => $body,
            'quote_updated_at' => $selectedAt,
            'price' => $quotePrice,
            'eta' => $deliveryTime,
        ]);
        $this->persistIfoodQuoteState(
            $order,
            array_merge($this->getStoredIfoodQuoteState($order), $ifoodState),
            [
                'flow' => 'quote',
                'provider_key' => 'ifood',
                'provider_label' => 'iFood',
                'quote_state' => 'selected',
                'quote_id' => $quoteId,
                'selected_at' => $selectedAt,
                'requested_at' => $selectedAt,
                'remote_order_id' => $remoteOrderId !== '' ? $remoteOrderId : null,
                'tracking_url' => $trackingUrl !== '' ? $trackingUrl : null,
                'delivery_response' => $body,
                'quote_response' => $quoteResponse,
                'price' => $quotePrice,
                'eta' => $deliveryTime,
                'merchant_id' => $merchantId,
            ]
        );

        return [
            'errno' => 0,
            'errmsg' => 'ok',
            'data' => [
                'order_id' => $order->getId(),
                'remote_order_id' => $remoteOrderId !== '' ? $remoteOrderId : null,
                'tracking_url' => $trackingUrl !== '' ? $trackingUrl : null,
                'quote_id' => $quoteId,
                'delivery' => $body,
            ],
        ];
    }

    public function connectStore(People $provider, string $merchantId, ?array $merchant = null): array
    {
        $this->init();
        $normalizedMerchantId = $this->normalizeString($merchantId);
        if ($normalizedMerchantId === '') {
            return [
                'errno' => 10002,
                'errmsg' => 'merchant_id obrigatorio',
            ];
        }

        $merchantName = '';
        $merchantStatus = '';
        if (is_array($merchant)) {
            $merchantName = $this->normalizeString($merchant['name'] ?? $merchant['merchant_name'] ?? null);
            $merchantStatus = strtoupper($this->normalizeString($merchant['status'] ?? $merchant['merchant_status'] ?? null));
        }

        $remoteConnected = $merchantName !== '' || $merchantStatus !== '' ? '1' : '0';

        $this->persistProviderIntegrationState($provider, [
            'code' => $normalizedMerchantId,
            'merchant_name' => $merchantName,
            'merchant_status' => $merchantStatus,
            'remote_connected' => $remoteConnected,
            'connected_at' => date('Y-m-d H:i:s'),
            'disconnected_at' => '',
            'last_sync_at' => date('Y-m-d H:i:s'),
            'last_error_code' => '',
            'last_error_message' => '',
        ]);

        $this->entityManager->flush();

        return [
            'errno' => 0,
            'errmsg' => 'ok',
            'state' => $this->getStoredIntegrationState($provider),
        ];
    }

    public function disconnectStore(People $provider): array
    {
        $this->init();

        $this->persistProviderIntegrationState($provider, [
            'code' => '',
            'merchant_id' => '',
            'merchant_name' => '',
            'merchant_status' => '',
            'remote_connected' => '0',
            'disconnected_at' => date('Y-m-d H:i:s'),
            'last_sync_at' => date('Y-m-d H:i:s'),
            'last_error_code' => '',
            'last_error_message' => '',
        ]);

        $this->entityManager->flush();

        return [
            'errno' => 0,
            'errmsg' => 'ok',
            'state' => $this->getStoredIntegrationState($provider),
        ];
    }

    /* Retorna status das operacoes da loja.
     * GET /merchant/v1.0/merchants/{id}/status → array de {operation, salesChannel, available, state, validations}
     * state possíveis: OK, WARNING, CLOSED, ERROR, UNAVAILABLE
     */
    public function getStoreStatus(People $provider): array
    {
        $this->init();
        $stored     = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($stored['merchant_id'] ?? null);
        if ($merchantId === '') {
            return ['errno' => 10002, 'errmsg' => 'Loja iFood nao conectada.', 'data' => null];
        }
        $token = $this->getAccessToken();
        if (!$token) {
            return ['errno' => 10001, 'errmsg' => 'Token iFood indisponivel.', 'data' => null];
        }
        try {
            $response   = $this->httpClient->request('GET',
                self::API_BASE_URL . '/merchant/v1.0/merchants/' . rawurlencode($merchantId) . '/status',
                ['headers' => ['Authorization' => 'Bearer ' . $token]]);
            $statusCode = $response->getStatusCode();
            $content    = $response->getContent(false);
            $decoded    = json_decode($content, true);
            if (!is_array($decoded)) $decoded = [];

            if ($statusCode < 200 || $statusCode >= 300) {
                return [
                    'errno'  => $statusCode,
                    'errmsg' => $this->normalizeString(
                        (is_array($decoded) ? ($decoded['message'] ?? $decoded['description'] ?? null) : null)
                        ?? 'Falha ao obter status da loja'
                    ),
                    'data' => null,
                ];
            }

            /* A API retorna um array de operações.
             * Considera a loja "online" se ao menos uma operação está OK ou WARNING.
             */
            $operations = array_is_list($decoded) ? $decoded : [$decoded];
            $online     = false;
            $normalizedOps = [];
            foreach ($operations as $op) {
                $opState    = strtoupper($this->normalizeString($op['state'] ?? null));
                $opOnline   = in_array($opState, ['OK', 'WARNING'], true)
                    || (isset($op['available']) && (bool) $op['available'] === true);
                if ($opOnline) $online = true;
                $normalizedOps[] = [
                    'operation'    => $this->normalizeString($op['operation'] ?? null),
                    'sales_channel'=> $this->normalizeString($op['salesChannel'] ?? null),
                    'available'    => isset($op['available']) ? (bool) $op['available'] : $opOnline,
                    'state'        => $opState,
                    'state_label'  => $this->normalizeOperationStateLabel($opState),
                    'message'      => $op['message'] ?? null,
                    'validations'  => $op['validations'] ?? [],
                ];
            }

            /* Obtém interrupções ativas */
            $interruptions = $this->listInterruptionsRaw($merchantId, $token);

            return [
                'errno'  => 0,
                'errmsg' => 'ok',
                'data'   => [
                    'online'        => $online,
                    'operations'    => $normalizedOps,
                    'interruptions' => $interruptions,
                ],
            ];
        } catch (\Throwable $e) {
            self::$logger->error('iFood getStoreStatus failed', ['error' => $e->getMessage()]);
            return ['errno' => 1, 'errmsg' => 'Falha ao consultar status da loja iFood', 'data' => null];
        }
    }

    private function isStoreStatusWebhookEvent(array $event, string $eventCode): bool
    {
        $merchantId = $this->resolveWebhookMerchantId($event);
        if ($merchantId === '') {
            return false;
        }

        if ($this->normalizeString($event['orderId'] ?? null) !== '') {
            return false;
        }

        $status = $this->resolveWebhookMerchantStatus($event);
        if ($status !== null) {
            return true;
        }

        $normalizedEventCode = strtoupper($this->normalizeString($eventCode));
        return in_array($normalizedEventCode, [
            'STORE_STATUS_CHANGED',
            'MERCHANT_STATUS_CHANGED',
            'MERCHANT_STATUS_UPDATE',
            'MERCHANT_ONLINE',
            'MERCHANT_OFFLINE',
            'AVAILABLE',
            'UNAVAILABLE',
            'ONLINE',
            'OFFLINE',
            'OPEN',
            'CLOSED',
            'INACTIVE',
        ], true);
    }

    private function resolveWebhookMerchantId(array $event): string
    {
        return $this->normalizeString(
            $event['merchantId']
                ?? $event['merchant_id']
                ?? $event['merchantID']
                ?? ($event['merchant']['id'] ?? null)
                ?? ($event['merchant']['merchantId'] ?? null)
                ?? ($event['merchant']['merchant_id'] ?? null)
                ?? null
        );
    }

    private function resolveWebhookMerchantStatus(array $event): ?string
    {
        $candidates = [
            $event['merchantStatus'] ?? null,
            $event['merchant_status'] ?? null,
            $event['status'] ?? null,
            $event['state'] ?? null,
            $event['merchant']['status'] ?? null,
            $event['merchant']['merchantStatus'] ?? null,
            $event['merchant']['merchant_status'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = strtoupper($this->normalizeString($candidate));
            if ($normalized === '') {
                continue;
            }

            if (in_array($normalized, ['AVAILABLE', 'UNAVAILABLE', 'ONLINE', 'OFFLINE', 'OPEN', 'CLOSED', 'INACTIVE'], true)) {
                return $normalized;
            }

            if (is_bool($candidate)) {
                return $candidate ? 'AVAILABLE' : 'UNAVAILABLE';
            }

            if (is_numeric($candidate)) {
                return (int) $candidate === 1 ? 'AVAILABLE' : 'UNAVAILABLE';
            }
        }

        $eventCode = strtoupper($this->normalizeString(
            $event['fullCode']
                ?? $event['code']
                ?? $event['type']
                ?? $event['eventType']
                ?? null
        ));

        if (in_array($eventCode, ['AVAILABLE', 'ONLINE', 'OPEN'], true)) {
            return 'AVAILABLE';
        }

        if (in_array($eventCode, ['UNAVAILABLE', 'OFFLINE', 'CLOSED', 'INACTIVE'], true)) {
            return 'UNAVAILABLE';
        }

        if ($eventCode !== '' && str_contains($eventCode, 'OPEN')) {
            return 'AVAILABLE';
        }

        if ($eventCode !== '' && str_contains($eventCode, 'CLOSE')) {
            return 'UNAVAILABLE';
        }

        return null;
    }

    private function syncStoreStatusWebhook(array $event, ?Integration $integration = null): void
    {
        $merchantId = $this->resolveWebhookMerchantId($event);
        if ($merchantId === '') {
            self::$logger->warning('iFood store status webhook ignored because merchantId is missing', [
                'integration_id' => $integration?->getId(),
                'event_code' => $this->resolveEventCode($event),
            ]);
            return;
        }

        $provider = $this->findEntityByExtraData('People', 'code', $merchantId, People::class);
        if (!$provider instanceof People && ctype_digit($merchantId)) {
            $provider = $this->entityManager->getRepository(People::class)->find((int) $merchantId);
        }

        if (!$provider instanceof People) {
            self::$logger->warning('iFood store status webhook ignored because provider was not found', [
                'integration_id' => $integration?->getId(),
                'merchant_id' => $merchantId,
                'event_code' => $this->resolveEventCode($event),
            ]);
            return;
        }

        $merchantStatus = $this->resolveWebhookMerchantStatus($event);
        if ($merchantStatus === null) {
            self::$logger->warning('iFood store status webhook ignored because status could not be resolved', [
                'integration_id' => $integration?->getId(),
                'merchant_id' => $merchantId,
                'event_code' => $this->resolveEventCode($event),
            ]);
            return;
        }

        $previousState = $this->getStoredIntegrationState($provider);
        $previousOnline = (bool) ($previousState['online'] ?? false);
        $currentOnline = in_array($merchantStatus, ['AVAILABLE', 'ONLINE', 'OPEN'], true);

        $this->persistProviderIntegrationState($provider, [
            'merchant_status' => $merchantStatus,
            'remote_connected' => '1',
            'online' => $currentOnline ? '1' : '0',
            'last_sync_at' => date('Y-m-d H:i:s'),
            'last_error_code' => '',
            'last_error_message' => '',
        ]);

        if ($previousOnline === $currentOnline) {
            return;
        }

        $providerName = '';
        if (method_exists($provider, 'getName')) {
            $providerName = trim((string) $provider->getName());
        }
        if ($providerName === '') {
            $providerName = 'Loja';
        }

        $events = [[
            'store' => 'orders',
            'event' => $currentOnline ? 'store.opened' : 'store.closed',
            'company' => $provider->getId(),
            'provider' => $provider->getId(),
            'providerName' => $providerName,
            'source' => self::APP_CONTEXT,
            'merchantId' => $merchantId,
            'status' => $currentOnline ? 'open' : 'closed',
            'realStatus' => $currentOnline ? 'open' : 'closed',
            'merchantStatus' => $merchantStatus,
            'message' => sprintf(
                'Loja %s foi %s',
                $providerName,
                $currentOnline ? 'aberta' : 'fechada'
            ),
            'sentAt' => date(DATE_ATOM),
            'alertSound' => true,
        ]];

        if ($currentOnline) {
            $events[0]['notificationHeader'] = sprintf('%s foi aberta', $providerName);
            $events[0]['notificationSubheader'] = 'A loja voltou a ficar online.';
            $events[0]['notificationStatusLabel'] = 'Aberta';
        } else {
            $summary = $this->sendStoreClosingNotifications($provider, self::APP_CONTEXT);
            $events[0]['notificationHeader'] = sprintf('%s foi fechada', $providerName);
            $events[0]['notificationSubheader'] = sprintf(
                'Vendas do dia: R$ %s',
                number_format((float) ($summary['daily_sales_amount'] ?? 0), 2, ',', '.')
            );
            $events[0]['notificationBody'] = sprintf(
                'Fatura da semana: R$ %s',
                number_format((float) ($summary['weekly_settlement_amount'] ?? 0), 2, ',', '.')
            );
            $events[0]['notificationStatusLabel'] = 'Fechada';
        }

        $this->broadcastCompanyWebsocketEvents($provider, $events);
    }

    /* Fecha a loja criando uma interrupção de até 7 dias (máximo permitido pela API).
     * POST /merchant/v1.0/merchants/{id}/interruptions
     */
    private function normalizeIfoodInterruptionPayload(array $payload): array
    {
        $description = $this->normalizeString($payload['description'] ?? null);
        $start = $this->normalizeString($payload['start'] ?? null);
        $end = $this->normalizeString($payload['end'] ?? null);

        if ($description === '') {
            $description = 'Loja fechada pelo gestor';
        }

        if ($start === '' || $end === '') {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $start = $start !== '' ? $start : $now->format(\DateTimeInterface::ATOM);
            $end = $end !== '' ? $end : $now->modify('+7 days')->format(\DateTimeInterface::ATOM);
        }

        try {
            $startDate = new \DateTimeImmutable($start);
            $endDate = new \DateTimeImmutable($end);
            if ($endDate <= $startDate) {
                return ['error' => 'A data final da pausa deve ser maior que a data inicial.'];
            }

            if (($endDate->getTimestamp() - $startDate->getTimestamp()) > 604800) {
                return ['error' => 'A pausa iFood nao pode ultrapassar 7 dias.'];
            }
        } catch (\Throwable) {
            return ['error' => 'Datas da pausa devem estar em formato ISO 8601.'];
        }

        return [
            'description' => mb_substr($description, 0, 255),
            'start' => $start,
            'end' => $end,
        ];
    }

    public function closeStore(People $provider, array $interruptionPayload = []): array
    {
        $this->init();
        $stored     = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($stored['merchant_id'] ?? null);
        if ($merchantId === '') {
            return ['errno' => 10002, 'errmsg' => 'Loja iFood nao conectada.', 'data' => null];
        }
        $token = $this->getAccessToken();
        if (!$token) {
            return ['errno' => 10001, 'errmsg' => 'Token iFood indisponivel.', 'data' => null];
        }

        $interruption = $this->normalizeIfoodInterruptionPayload($interruptionPayload);
        if (isset($interruption['error'])) {
            return ['errno' => 10003, 'errmsg' => $interruption['error'], 'data' => null];
        }

        try {
            $response   = $this->httpClient->request('POST',
                self::API_BASE_URL . '/merchant/v1.0/merchants/' . rawurlencode($merchantId) . '/interruptions',
                [
                    'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                    'json'    => $interruption,
                ]);
            $statusCode = $response->getStatusCode();
            $content    = $response->getContent(false);
            $decoded    = json_decode($content, true);
            if (!is_array($decoded)) $decoded = [];

            if ($statusCode < 200 || $statusCode >= 300) {
                return [
                    'errno'  => $statusCode,
                    'errmsg' => $this->normalizeString(
                        $decoded['message'] ?? $decoded['description'] ?? 'Falha ao fechar a loja no iFood'
                    ),
                    'data' => null,
                ];
            }

            return [
                'errno'  => 0,
                'errmsg' => 'ok',
                'data'   => ['interruption' => $decoded, 'online' => false],
            ];
        } catch (\Throwable $e) {
            self::$logger->error('iFood closeStore failed', ['error' => $e->getMessage()]);
            return ['errno' => 1, 'errmsg' => 'Falha ao fechar a loja iFood', 'data' => null];
        }
    }

    /* Abre a loja removendo todas as interrupções ativas.
     * GET /interruptions → DELETE /interruptions/{id} para cada uma.
     */
    public function openStore(People $provider): array
    {
        $this->init();
        $stored     = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($stored['merchant_id'] ?? null);
        if ($merchantId === '') {
            return ['errno' => 10002, 'errmsg' => 'Loja iFood nao conectada.', 'data' => null];
        }
        $token = $this->getAccessToken();
        if (!$token) {
            return ['errno' => 10001, 'errmsg' => 'Token iFood indisponivel.', 'data' => null];
        }

        $interruptions = $this->listInterruptionsRaw($merchantId, $token);
        if (empty($interruptions)) {
            return ['errno' => 0, 'errmsg' => 'ok', 'data' => ['removed' => 0, 'online' => true]];
        }

        $removed = 0;
        $lastError = null;
        foreach ($interruptions as $interruption) {
            $id = $this->normalizeString($interruption['id'] ?? null);
            if ($id === '') continue;
            try {
                $resp = $this->httpClient->request('DELETE',
                    self::API_BASE_URL . '/merchant/v1.0/merchants/' . rawurlencode($merchantId) . '/interruptions/' . rawurlencode($id),
                    ['headers' => ['Authorization' => 'Bearer ' . $token]]);
                if ($resp->getStatusCode() < 300) {
                    $removed++;
                } else {
                    $lastError = 'Falha ao remover interrupcao ' . $id;
                }
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        if ($removed === 0 && $lastError !== null) {
            return ['errno' => 1, 'errmsg' => $lastError, 'data' => null];
        }

        return [
            'errno'  => 0,
            'errmsg' => 'ok',
            'data'   => ['removed' => $removed, 'online' => true],
        ];
    }

    public function removeStoreInterruption(People $provider, string $interruptionId): array
    {
        $this->init();
        $stored     = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($stored['merchant_id'] ?? null);
        $interruptionId = $this->normalizeString($interruptionId);
        if ($merchantId === '') {
            return ['errno' => 10002, 'errmsg' => 'Loja iFood nao conectada.', 'data' => null];
        }
        if ($interruptionId === '') {
            return ['errno' => 10003, 'errmsg' => 'Pausa iFood invalida.', 'data' => null];
        }
        $token = $this->getAccessToken();
        if (!$token) {
            return ['errno' => 10001, 'errmsg' => 'Token iFood indisponivel.', 'data' => null];
        }

        try {
            $response = $this->httpClient->request('DELETE',
                self::API_BASE_URL . '/merchant/v1.0/merchants/' . rawurlencode($merchantId) . '/interruptions/' . rawurlencode($interruptionId),
                ['headers' => ['Authorization' => 'Bearer ' . $token]]);
            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                return ['errno' => 0, 'errmsg' => 'ok', 'data' => ['removed' => 1, 'interruption_id' => $interruptionId]];
            }

            $content = $response->getContent(false);
            $decoded = json_decode($content, true);
            return [
                'errno' => $statusCode,
                'errmsg' => $this->normalizeString(
                    (is_array($decoded) ? ($decoded['message'] ?? $decoded['description'] ?? null) : null)
                    ?? 'Falha ao remover pausa iFood'
                ),
                'data' => null,
            ];
        } catch (\Throwable $e) {
            self::$logger->error('iFood removeStoreInterruption failed', ['error' => $e->getMessage()]);
            return ['errno' => 1, 'errmsg' => 'Falha ao remover pausa iFood', 'data' => null];
        }
    }

    private function listInterruptionsRaw(string $merchantId, string $token): array
    {
        try {
            $response   = $this->httpClient->request('GET',
                self::API_BASE_URL . '/merchant/v1.0/merchants/' . rawurlencode($merchantId) . '/interruptions',
                ['headers' => ['Authorization' => 'Bearer ' . $token]]);
            $statusCode = $response->getStatusCode();
            // Aceita qualquer 2xx; 204 = lista vazia valida
            if ($statusCode < 200 || $statusCode >= 300) return [];
            $content = (string) $response->getContent(false);
            if ($content === '' || $content === 'null') return [];
            $decoded = json_decode($content, true);
            if (!is_array($decoded)) return [];
            // Suporte a lista plana [...] ou encapsulada {"interruptions":[...]}
            if (array_is_list($decoded)) return $decoded;
            if (isset($decoded['interruptions']) && is_array($decoded['interruptions'])) {
                return $decoded['interruptions'];
            }
            return [];
        } catch (\Throwable $e) {
            self::$logger->warning('iFood listInterruptionsRaw failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function normalizeOperationStateLabel(string $state): string
    {
        return match ($state) {
            'OK'          => 'Online',
            'WARNING'     => 'Online (com aviso)',
            'CLOSED'      => 'Fechada',
            'ERROR'       => 'Erro',
            'UNAVAILABLE' => 'Indisponivel',
            default       => 'Indefinido',
        };
    }

    public function syncIntegrationState(People $provider): array
    {
        $this->init();
        $state = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($state['merchant_id'] ?? null);
        $storesResponse = $this->listMerchantsRaw();
        $merchants = is_array($storesResponse['data']['merchants'] ?? null) ? $storesResponse['data']['merchants'] : [];

        if ((int) ($storesResponse['errno'] ?? 1) !== 0) {
            $this->persistProviderIntegrationState($provider, [
                'last_sync_at' => date('Y-m-d H:i:s'),
                'last_error_code' => (string) ($storesResponse['errno'] ?? 1),
                'last_error_message' => $this->normalizeString($storesResponse['errmsg'] ?? 'Falha ao sincronizar iFood'),
                'remote_connected' => '0',
            ]);
            $this->entityManager->flush();

            return [
                'errno' => (int) ($storesResponse['errno'] ?? 1),
                'errmsg' => $this->normalizeString($storesResponse['errmsg'] ?? 'Falha ao sincronizar iFood'),
                'stores' => $merchants,
                'state' => $this->getStoredIntegrationState($provider),
            ];
        }

        $matchedStore = null;
        if ($merchantId !== '') {
            foreach ($merchants as $store) {
                if ($this->normalizeString($store['merchant_id'] ?? null) === $merchantId) {
                    $matchedStore = $store;
                    break;
                }
            }
        }

        if ($matchedStore) {
            /* O endpoint de listagem nao retorna "status". Busca o detalhe da loja
             * para obter o campo status real (AVAILABLE, UNAVAILABLE, etc.).
             */
            $detailStatus = strtoupper($this->normalizeString($matchedStore['status'] ?? null));
            if ($detailStatus === '' && $merchantId !== '') {
                $token  = $this->getAccessToken();
                $detail = $token ? $this->getMerchantDetailRaw($merchantId, $token) : ['errno' => 1, 'data' => null];
                if ((int) ($detail['errno'] ?? 1) === 0 && is_array($detail['data'])) {
                    $detailStatus = strtoupper($this->normalizeString(
                        $detail['data']['status'] ?? $detail['data']['merchantStatus'] ?? null
                    ));
                }
            }

            $this->persistProviderIntegrationState($provider, [
                'merchant_name' => $this->normalizeString($matchedStore['name'] ?? null),
                'merchant_status' => $detailStatus,
                'remote_connected' => '1',
                'last_sync_at' => date('Y-m-d H:i:s'),
                'last_error_code' => '',
                'last_error_message' => '',
            ]);
        } elseif ($merchantId !== '') {
            $this->persistProviderIntegrationState($provider, [
                'remote_connected' => '0',
                'last_sync_at' => date('Y-m-d H:i:s'),
                'last_error_code' => '404',
                'last_error_message' => 'Loja vinculada nao encontrada na conta iFood',
            ]);
        } else {
            $this->persistProviderIntegrationState($provider, [
                'remote_connected' => '0',
                'last_sync_at' => date('Y-m-d H:i:s'),
                'last_error_code' => '',
                'last_error_message' => '',
            ]);
        }

        $this->entityManager->flush();

        return [
            'errno' => 0,
            'errmsg' => 'ok',
            'stores' => $merchants,
            'store' => $matchedStore,
            'state' => $this->getStoredIntegrationState($provider),
        ];
    }

    // CANCELAMENTO DE PEDIDO
    // Busca pedido pelo orderId do iFood, marca como cancelado e atualiza banco
    private function cancelOrder(array $json): ?Order
    {
        $orderId = $this->normalizeString($json['orderId'] ?? null);
        $order = $this->findOrderByExternalId($orderId);
        if (!$order instanceof Order) {
            return null;
        }

        $status = $this->statusService->discoveryStatus('canceled', 'canceled', 'order');
        if ($status) {
            $order->setStatus($status);
        }

        $eventCode = $this->resolveEventCode($json);
        $other = (array) $order->getOtherInformations(true);
        $other[$eventCode !== '' ? $eventCode : 'CANCELLED'] = $json;
        $other['latest_event_type'] = $eventCode !== '' ? $eventCode : 'CANCELLED';
        $order->addOtherInformations(self::$app, $other);

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        //@todo cancelar faturas
        return $order;
    }
    // CRIAÇÃO DE NOVO PEDIDO
    // Valida IDs, busca restaurante e cliente, pega detalhes do iFood,
    // cria pedido com produtos, entrega e pagamentos, grava no banco
    private function addOrder(array $json): ?Order
    {
        $orderId = $this->normalizeString($json['orderId'] ?? null);
        $merchantId = $this->normalizeString($json['merchantId'] ?? null);

        if ($orderId === '' || $merchantId === '') {
            self::$logger->warning('iFood order ignored because required identifiers are missing', [
                'order_id' => $orderId,
                'merchant_id' => $merchantId,
            ]);
            return null;
        }

        $provider = $this->findEntityByExtraData('People', 'code', $merchantId, People::class);

        if (!$provider instanceof People) {
            self::$logger->warning('iFood order ignored because provider mapping was not found', [
                'order_id' => $orderId,
                'merchant_id' => $merchantId,
            ]);
            return null;
        }

        if (!$this->acquireOrderIntegrationLock($orderId)) {
            return $this->findOrderByExternalId($orderId);
        }

        try {
            $order = $this->findOrderByExternalId($orderId);
            if ($order instanceof Order) {
                return $order;
            }

            $orderDetails = $this->resolveOrderDetailsFromEvent($orderId, $json);

            if (!$orderDetails) {
                self::$logger->error('iFood order details could not be fetched after retries', [
                    'order_id' => $orderId,
                    'merchant_id' => $merchantId,
                ]);
                // For PLACED events this is commonly transient (eventual consistency / temporary API failure).
                // Throwing here lets Messenger retry instead of silently closing the integration item.
                throw new \RuntimeException(sprintf(
                    'iFood order details unavailable for order %s',
                    $orderId
                ));
            }

            $json['order'] = $orderDetails;
            $status = $this->statusService->discoveryStatus('open', 'open', 'order');

            $client = $this->discoveryClient($provider, is_array($orderDetails['customer'] ?? null) ? $orderDetails['customer'] : []);
            if (!$client instanceof People) {
                self::$logger->error('iFood order ignored because client could not be resolved', [
                    'order_id' => $orderId,
                    'merchant_id' => $merchantId,
                ]);
                return null;
            }

            $orderAmount = isset($orderDetails['total']['orderAmount']) ? (float) $orderDetails['total']['orderAmount'] : 0.0;
            $eventCode = $this->resolveEventCode($json);
            $snapshotKey = $eventCode !== '' ? $eventCode : 'PLACED';
            $order = $this->createOrder($client, $provider, $orderAmount, $status, [
                $snapshotKey => $json,
                'latest_event_type' => $snapshotKey,
            ]);
            $this->syncOrderComments($order, $this->extractOrderRemarkFromPayload($orderDetails));

            // Vincula o identificador remoto logo apos o shell order existir para evitar
            // pedidos duplicados quando outros eventos chegarem enquanto o enriquecimento
            // (itens/endereco/financeiro) ainda estiver acontecendo.
            $this->entityManager->persist($order);
            $this->entityManager->flush();
            $this->discoveryFoodCode($order, $orderId, 'id');
            $autoConfirmed = $this->autoConfirmOrder($order, $orderId);

            $this->addProducts($order, is_array($orderDetails['items'] ?? null) ? $orderDetails['items'] : []);
            if (is_array($orderDetails['delivery'] ?? null)) {
                try {
                    $this->addDelivery($order, $orderDetails);
                } catch (\Throwable $exception) {
                    self::$logger->warning('iFood order delivery enrichment failed during creation pipeline', [
                        'order_id' => $orderId,
                        'local_order_id' => $order->getId(),
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
            if (is_array($orderDetails['payments']['methods'] ?? null) && is_array($orderDetails['total'] ?? null)) {
                try {
                    $this->addPayments($order, $orderDetails);
                } catch (\Throwable $exception) {
                    self::$logger->warning('iFood order payment enrichment failed during creation pipeline', [
                        'order_id' => $orderId,
                        'local_order_id' => $order->getId(),
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $this->entityManager->persist($order);
            $this->entityManager->flush();

            $extendedState = [
                'id' => $orderId,
                'code' => $this->normalizeString($orderDetails['displayId'] ?? null),
                'merchant_id' => $merchantId,
                'last_event_type' => $snapshotKey,
                'last_event_at' => $this->extractEventTimestamp($json),
                'remote_order_state' => $this->resolveRemoteOrderStateByEventCode($snapshotKey),
            ];
            $extendedState = array_merge($extendedState, $this->extractOrderDetailSnapshot($orderDetails));
            $this->persistOrderIntegrationState($order, $extendedState);
            $this->entityManager->flush();

            self::$logger->info('iFood order integrated successfully', [
                'order_id' => $orderId,
                'merchant_id' => $merchantId,
                'local_order_id' => $order->getId(),
            ]);

            $this->printOrder($order);
            if (!$autoConfirmed) {
                $this->autoConfirmOrder($order, $orderId);
            }
            return $order;
        } catch (\Throwable $exception) {
            self::$logger->error('iFood order integration failed during creation pipeline', [
                'order_id' => $orderId,
                'merchant_id' => $merchantId,
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        } finally {
            $this->releaseOrderIntegrationLock($orderId);
        }
    }

    private function resolveMappedOrderStatus(string $status, string $realStatus, string $orderId, string $merchantId): ?Status
    {
        $attempts = [[$status, $realStatus]];

        foreach ($this->resolveStatusAliases($status, $realStatus) as $alias) {
            $attempts[] = [$alias, $realStatus];
            $attempts[] = [$alias, null];
        }

        foreach ($attempts as [$candidateStatus, $candidateRealStatus]) {
            $resolved = $this->findMappedOrderStatus((string) $candidateStatus, $candidateRealStatus);
            if ($resolved instanceof Status) {
                return $resolved;
            }
        }

        $resolvedByRealStatus = $this->findMappedOrderStatusByRealStatus($realStatus);
        if ($resolvedByRealStatus instanceof Status) {
            return $resolvedByRealStatus;
        }

        self::$logger->error('iFood order status mapping not found in local status table', [
            'order_id' => $orderId,
            'merchant_id' => $merchantId,
            'status' => $status,
            'real_status' => $realStatus,
        ]);

        return null;
    }

    private function resolveStatusAliases(string $status, string $realStatus): array
    {
        $normalizedStatus = strtolower(trim($status));
        $normalizedRealStatus = strtolower(trim($realStatus));

        if ($normalizedStatus === 'pending' || $normalizedRealStatus === 'pending') {
            return ['pending payment', 'open'];
        }

        if ($normalizedStatus === 'paid') {
            return ['open', 'confirmed'];
        }

        return [];
    }

    private function findMappedOrderStatus(string $status, ?string $realStatus = null): ?Status
    {
        $repository = $this->entityManager->getRepository(Status::class);
        $criteria = [
            'status' => $status,
            'context' => 'order',
        ];

        if ($realStatus !== null) {
            $criteria['realStatus'] = $realStatus;
        }

        $resolved = $repository->findOneBy($criteria);
        if ($resolved instanceof Status) {
            return $resolved;
        }

        if ($realStatus !== null) {
            $fallback = $repository->findOneBy([
                'status' => $status,
                'context' => 'order',
            ]);
            if ($fallback instanceof Status) {
                return $fallback;
            }
        }

        return null;
    }

    private function findMappedOrderStatusByRealStatus(string $realStatus): ?Status
    {
        if ($realStatus === '') {
            return null;
        }

        return $this->entityManager->getRepository(Status::class)->createQueryBuilder('status')
            ->andWhere('status.context = :context')
            ->andWhere('LOWER(status.realStatus) = :realStatus')
            ->setParameter('context', 'order')
            ->setParameter('realStatus', strtolower(trim($realStatus)))
            ->orderBy('status.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function autoConfirmOrder(Order $order, string $orderId): bool
    {
        try {
            $raw = $this->confirmOrder($orderId);
            if ($raw) {
                $result = $this->persistOrderActionResult(
                    $order,
                    'confirm',
                    $raw,
                    'confirmed',
                    ['realStatus' => 'open', 'status' => 'preparing']
                );
                $this->entityManager->flush();
                if ((string) ($result['errno'] ?? '') === '0') {
                    self::$logger->info('iFood order auto-confirmed on entry', [
                        'order_id' => $orderId,
                        'local_order_id' => $order->getId(),
                    ]);
                    return true;
                }
            }
        } catch (\Throwable $e) {
            self::$logger->warning('iFood order auto-confirm failed', [
                'order_id' => $orderId,
                'local_order_id' => $order->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }
    // FATURAS DE RECEBIMENTO (Pagamentos)
    // Para cada método de pagamento, cria fatura de recebimento no banco
    private function addReceiveInvoices(Order $order, array $payments)
    {
        $paidStatus = $this->statusService->discoveryStatus('closed', 'paid', 'invoice');

        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }

            $amount = round((float) ($payment['value'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            $paymentTypeData = $this->resolveIfoodInvoicePaymentTypeData($payment);
            $isPrepaid = (bool) ($payment['prepaid'] ?? false);
            $paymentType = $this->resolveIfoodProviderPaymentType(
                $order->getProvider(),
                $paymentTypeData
            );
            $receivableWallet = $this->resolveIfoodReceivableWallet(
                $order,
                $paymentType,
                $paymentTypeData,
                $isPrepaid
            );
            $this->ensureIfoodWalletPaymentType(
                $receivableWallet,
                $paymentType,
                $paymentTypeData['paymentCode'] ?? null
            );

            $invoice = $this->invoiceService->createInvoiceByOrder(
                $order,
                $amount,
                $isPrepaid ? $paidStatus : null,
                new DateTime(),
                null,
                $receivableWallet
            );

            $this->applyIfoodInvoiceContract(
                $invoice,
                $paymentType,
                [
                    'financial_kind' => 'account_receivable',
                    'invoice_purpose' => 'customer_total',
                    'marketplace' => self::APP_CONTEXT,
                    'is_paid_online' => $isPrepaid,
                    'payment_value' => $amount,
                    'pay_type' => $paymentTypeData['pay_type'] ?? '',
                    'pay_method' => $paymentTypeData['pay_method'] ?? '',
                    'pay_channel' => $paymentTypeData['pay_channel'] ?? '',
                    'selected_payment_label' => $paymentTypeData['selected_payment_label'] ?? '',
                    'payment_liability' => $paymentTypeData['payment_liability'] ?? '',
                    'payment_wallet_name' => $paymentTypeData['payment_wallet_name'] ?? '',
                ],
                $isPrepaid ? $paidStatus : null,
                null,
                $receivableWallet
            );
        }
    }

    private function mergeIfoodOrderDetails(array $primary, array $fallback): array
    {
        if (!$primary) {
            return $fallback;
        }

        if (!$fallback) {
            return $primary;
        }

        $merged = $fallback;

        foreach ($primary as $key => $value) {
            if (is_array($value)) {
                $fallbackValue = is_array($fallback[$key] ?? null) ? $fallback[$key] : [];

                if (array_is_list($value)) {
                    $merged[$key] = !empty($value) ? $value : $fallbackValue;
                    continue;
                }

                $merged[$key] = $this->mergeIfoodOrderDetails($value, $fallbackValue);
                continue;
            }

            $normalizedValue = $this->normalizeString($value);
            if ($normalizedValue !== '') {
                $merged[$key] = $value;
                continue;
            }

            if (!array_key_exists($key, $merged)) {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    private function refreshOrderCoreDataFromEvent(Order $order, array $event): array
    {
        $orderId = $this->normalizeString($event['orderId'] ?? null);
        if ($orderId === '') {
            return [];
        }

        $orderDetails = $this->resolveOrderDetailsFromEvent($orderId, $event, $order);
        if (!$orderDetails) {
            return [];
        }

        $provider = $order->getProvider();
        if ($provider instanceof People) {
            $client = $this->discoveryClient(
                $provider,
                is_array($orderDetails['customer'] ?? null) ? $orderDetails['customer'] : []
            );

            if ($client instanceof People) {
                $previousClientId = $order->getClient()?->getId();
                $previousPayerId = $order->getPayer()?->getId();

                $order->setClient($client);

                if (!($order->getPayer() instanceof People) || $previousPayerId === $previousClientId) {
                    $order->setPayer($client);
                }
            }
        }

        if (is_array($orderDetails['delivery'] ?? null)) {
            try {
                $this->addDelivery($order, $orderDetails);
            } catch (\Throwable $exception) {
                self::$logger->warning('iFood order delivery enrichment failed during event refresh', [
                    'order_id' => $orderId,
                    'local_order_id' => $order->getId(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->syncOrderComments($order, $this->extractOrderRemarkFromPayload($orderDetails));

        return $orderDetails;
    }

    private function resolveIfoodCountryEntity(?string $countryCode): ?\ControleOnline\Entity\Country
    {
        $normalizedCountryCode = strtoupper($this->normalizeString($countryCode));
        $repository = $this->entityManager->getRepository(\ControleOnline\Entity\Country::class);

        if ($normalizedCountryCode !== '') {
            $country = $repository->findOneBy(['countrycode' => $normalizedCountryCode]);
            if ($country instanceof \ControleOnline\Entity\Country) {
                return $country;
            }

            $country = $repository->findOneBy(['isoalpha3' => $normalizedCountryCode]);
            if ($country instanceof \ControleOnline\Entity\Country) {
                return $country;
            }
        }

        return $repository->findOneBy(['countrycode' => 'BR']);
    }

    private function resolveIfoodAddressStateCode(?\ControleOnline\Entity\Country $country, ?string $stateValue): string
    {
        $normalizedState = strtoupper($this->normalizeString($stateValue));
        if ($normalizedState === '') {
            return '';
        }

        if (!$country instanceof \ControleOnline\Entity\Country) {
            return strlen($normalizedState) === 2 ? $normalizedState : '';
        }

        $stateRepository = $this->entityManager->getRepository(\ControleOnline\Entity\State::class);
        $stateByUf = $stateRepository->findOneBy([
            'country' => $country,
            'uf' => $normalizedState,
        ]);
        if ($stateByUf instanceof \ControleOnline\Entity\State) {
            return $stateByUf->getUf();
        }

        if (strlen($normalizedState) === 2) {
            return $normalizedState;
        }

        $stateByName = $stateRepository->createQueryBuilder('state')
            ->andWhere('state.country = :country')
            ->andWhere('LOWER(state.state) = :stateName')
            ->setParameter('country', $country)
            ->setParameter('stateName', strtolower($this->normalizeString($stateValue)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($stateByName instanceof \ControleOnline\Entity\State) {
            return $stateByName->getUf();
        }

        return '';
    }

    private function resolveIfoodStreetNumberPayload(array $deliveryAddress): array
    {
        $rawStreetNumber = $this->normalizeString($deliveryAddress['streetNumber'] ?? null);
        $streetNumber = $rawStreetNumber !== '' && ctype_digit($rawStreetNumber)
            ? (int) $rawStreetNumber
            : null;

        $complement = $this->normalizeString($deliveryAddress['complement'] ?? null);
        $shouldPreserveRawNumber = $rawStreetNumber !== ''
            && $streetNumber === null
            && stripos($complement, $rawStreetNumber) === false;

        if ($shouldPreserveRawNumber) {
            $complement = trim(sprintf('%s %s', $rawStreetNumber, $complement));
        }

        return [
            'street_number' => $streetNumber,
            'street_number_raw' => $rawStreetNumber,
            'complement' => $complement,
        ];
    }

    private function resolveIfoodQuoteSourceOrder(Order $order): Order
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

    private function resolveIfoodQuotePickupAddress(Order $order, Order $sourceOrder): ?Address
    {
        $pickupAddress = $this->resolveAddressCandidate($order->getAddressOrigin());
        if ($pickupAddress instanceof Address) {
            return $pickupAddress;
        }

        $pickupAddress = $this->resolveAddressCandidate($sourceOrder->getAddressOrigin());
        if ($pickupAddress instanceof Address) {
            return $pickupAddress;
        }

        $provider = $sourceOrder->getProvider();
        if ($provider instanceof People) {
            foreach ($provider->getAddress() as $address) {
                $resolvedAddress = $this->resolveAddressCandidate($address);
                if ($resolvedAddress instanceof Address) {
                    return $resolvedAddress;
                }
            }
        }

        return null;
    }

    private function resolveIfoodQuoteDropoffAddress(Order $order, Order $sourceOrder): ?Address
    {
        $dropoffAddress = $this->resolveAddressCandidate($order->getAddressDestination());
        if ($dropoffAddress instanceof Address) {
            return $dropoffAddress;
        }

        $dropoffAddress = $this->resolveAddressCandidate($sourceOrder->getAddressDestination());

        return $dropoffAddress instanceof Address ? $dropoffAddress : null;
    }

    private function validateIfoodQuoteRoute(?Address $pickupAddress, ?Address $dropoffAddress): ?string
    {
        if (!$pickupAddress instanceof Address) {
            return 'Pedido sem endereco de coleta valido.';
        }

        if (!$dropoffAddress instanceof Address) {
            return 'Pedido sem endereco de entrega valido.';
        }

        if (!$this->hasCompleteIfoodAddress($pickupAddress)) {
            return 'Pedido sem endereco de coleta valido.';
        }

        if (!$this->hasCompleteIfoodAddress($dropoffAddress)) {
            return 'Pedido sem endereco de entrega valido.';
        }

        if ($this->buildIfoodAddressRouteSignature($pickupAddress) === $this->buildIfoodAddressRouteSignature($dropoffAddress)) {
            return 'Endereco de coleta e entrega nao podem ser iguais.';
        }

        return null;
    }

    private function hasCompleteIfoodAddress(Address $address): bool
    {
        $street = $address->getStreet();
        $district = $street?->getDistrict();
        $city = $district?->getCity();
        $state = $city?->getState();
        $cep = $street?->getCep();

        return $this->normalizeString($street?->getStreet() ?? null) !== ''
            && $this->normalizeString($district?->getDistrict() ?? null) !== ''
            && $this->normalizeString($city?->getCity() ?? null) !== ''
            && $this->normalizeString($state?->getUf() ?: $state?->getState() ?: null) !== ''
            && $this->normalizeString($cep?->getCep() ?? null) !== '';
    }

    private function buildIfoodAddressRouteSignature(Address $address): string
    {
        $street = $address->getStreet();
        $district = $street?->getDistrict();
        $city = $district?->getCity();
        $state = $city?->getState();
        $cep = $street?->getCep();

        return strtolower(implode('|', [
            $this->normalizeString($street?->getStreet() ?? null),
            $this->normalizeString((string) $address->getNumber()),
            $this->normalizeString($address->getComplement() ?? null),
            $this->normalizeString($district?->getDistrict() ?? null),
            $this->normalizeString($city?->getCity() ?? null),
            $this->normalizeString($state?->getUf() ?: $state?->getState() ?: null),
            preg_replace('/\D+/', '', $this->normalizeString($cep?->getCep() ?? null)),
        ]));
    }

    private function buildIfoodAddressCoordinatesPayload(Address $address): array
    {
        $latitude = $address->getLatitude();
        $longitude = $address->getLongitude();

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return [];
        }

        return [
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
        ];
    }

    private function buildIfoodShippingAddressPayload(Address $address): array
    {
        $street = $address->getStreet();
        $district = $street?->getDistrict();
        $city = $district?->getCity();
        $state = $city?->getState();
        $cep = $street?->getCep();
        $streetNumberPayload = $this->resolveIfoodStreetNumberPayload([
            'streetNumber' => $address->getNumber(),
            'complement' => $address->getComplement(),
        ]);
        $coordinates = $this->buildIfoodAddressCoordinatesPayload($address);

        $payload = [
            'postalCode' => preg_replace('/\D+/', '', (string) ($cep?->getCep() ?? '')) ?: null,
            'streetNumber' => (string) ($streetNumberPayload['street_number'] ?? $address->getNumber() ?? ''),
            'streetName' => $this->truncateIfoodText($street?->getStreet() ?? '', 50),
            'complement' => $this->truncateIfoodText($streetNumberPayload['complement'] ?? $address->getComplement() ?? '', 50),
            'reference' => $this->truncateIfoodText($address->getLocator() ?: $address->getNickname() ?: '', 70),
            'neighborhood' => $this->truncateIfoodText($district?->getDistrict() ?? '', 50),
            'city' => $this->truncateIfoodText($city?->getCity() ?? '', 50),
            'state' => $this->truncateIfoodText(
                $state?->getUf() ?: $state?->getState() ?: '',
                2
            ),
            'country' => 'BR',
        ];

        if ($coordinates !== []) {
            $payload['coordinates'] = $coordinates;
        }

        return array_filter($payload, static fn(mixed $value): bool => $value !== null && $value !== '');
    }

    private function buildIfoodQuoteCustomerPayload(Order $order): array
    {
        $person = $order->getDeliveryContact();
        if (!$person instanceof People) {
            $person = $order->getClient();
        }

        $name = $this->resolveIfoodPeopleDisplayName($person);
        if ($name === '') {
            $name = 'Cliente';
        }

        $phone = $this->resolveIfoodPeoplePhone($person);
        if ($phone === null) {
            return [
                'name' => $this->truncateIfoodText($name, 50),
                'phone' => [
                    'type' => 'STORE',
                ],
            ];
        }

        return [
            'name' => $this->truncateIfoodText($name, 50),
            'phone' => [
                'type' => 'CUSTOMER',
                'countryCode' => $phone['countryCode'],
                'areaCode' => $phone['areaCode'],
                'number' => $phone['number'],
            ],
        ];
    }

    private function buildIfoodQuoteDeliveryPayload(
        Address $dropoffAddress,
        string $quoteId,
        array $quoteResponse,
        array $logisticsState
    ): array {
        $deliveryTime = is_array($quoteResponse['deliveryTime'] ?? null)
            ? $quoteResponse['deliveryTime']
            : [];
        $preparationTime = (int) (
            $deliveryTime['min']
                ?? $deliveryTime['preparationTime']
                ?? $logisticsState['preparation_time']
                ?? 0
        );
        $merchantFee = $this->normalizeIfoodMoneyValue(
            $quoteResponse['quote']['netValue']
                ?? $quoteResponse['quote']['net_value']
                ?? $quoteResponse['netValue']
                ?? $quoteResponse['net_value']
                ?? $logisticsState['price']
                ?? null
        );

        return [
            'merchantFee' => $merchantFee,
            'preparationTime' => max(0, $preparationTime),
            'quoteId' => $quoteId,
            'deliveryAddress' => $this->buildIfoodShippingAddressPayload($dropoffAddress),
        ];
    }

    private function buildIfoodQuoteItemsPayload(Order $order): array
    {
        $items = [];
        foreach ($order->getOrderProducts() as $orderProduct) {
            if (!$orderProduct instanceof OrderProduct) {
                continue;
            }

            if ($orderProduct->getOrderProduct() instanceof OrderProduct) {
                continue;
            }

            $product = $orderProduct->getProduct();
            $name = $this->truncateIfoodText(
                trim((string) ($product?->getProduct() ?? '')),
                50
            );
            if ($name === '') {
                $name = 'Item ' . ($orderProduct->getId() ?? 0);
            }

            $quantity = max(1, (int) round((float) $orderProduct->getQuantity()));
            $unitPrice = round((float) $orderProduct->getPrice(), 2);
            $price = round($unitPrice * $quantity, 2);
            $externalCode = trim((string) ($product?->getId() ?? $orderProduct->getId() ?? ''));

            $items[] = [
                'id' => $this->generateStableUuidFromSeed('ifood:shipping:item:' . $order->getId() . ':' . ($orderProduct->getId() ?? 0)),
                'name' => $name,
                'externalCode' => $externalCode !== '' ? $externalCode : $this->generateStableUuidFromSeed('ifood:shipping:external:' . $order->getId() . ':' . ($orderProduct->getId() ?? 0)),
                'quantity' => $quantity,
                'unitPrice' => $unitPrice,
                'price' => $price,
                'optionsPrice' => 0,
                'totalPrice' => $price,
            ];
        }

        return $items;
    }

    private function persistIfoodQuoteState(Order $order, array $storedState, array $logisticsState): void
    {
        $otherInformations = $this->getDecodedOrderOtherInformations($order);
        if ($otherInformations === []) {
            $otherInformations = [];
        }

        $context = $this->decodeOrderOtherInformationsValue($otherInformations[self::APP_CONTEXT] ?? null);
        if ($context === []) {
            $context = [];
        }

        $context['quote'] = $storedState;
        $otherInformations[self::APP_CONTEXT] = $context;
        $otherInformations['logistics'] = $logisticsState;

        $order->setOtherInformations($otherInformations);
        $order->setAlterDate(new DateTime('now'));
        if (isset($logisticsState['price']) && is_numeric($logisticsState['price'])) {
            $order->setPrice((float) $logisticsState['price']);
        }

        $this->entityManager->persist($order);
        $this->entityManager->flush();
    }

    private function persistIfoodQuoteFailure(Order $order, string $quoteState, string $message, array $extraState = []): array
    {
        $now = date('Y-m-d H:i:s');
        $storedState = array_merge($this->getStoredIfoodQuoteState($order), $extraState, [
            'quote_state' => $quoteState,
            'quote_message' => $message,
            'quote_requested_at' => $this->normalizeString($extraState['quote_requested_at'] ?? null) ?: $now,
            'quote_updated_at' => $now,
            'price' => null,
            'tracking_url' => null,
            'delivery_response' => $extraState['delivery_response'] ?? null,
        ]);
        $this->persistIfoodQuoteState($order, $storedState, array_merge([
            'flow' => 'quote',
            'provider_key' => 'ifood',
            'provider_label' => 'iFood',
            'quote_state' => $quoteState,
            'quote_message' => $message,
            'quote_requested_at' => $storedState['quote_requested_at'],
            'quote_updated_at' => $now,
            'price' => null,
            'tracking_url' => null,
        ], $extraState));

        return [
            'errno' => $extraState['quote_status'] ?? 422,
            'errmsg' => $message,
            'data' => [
                'order_id' => $order->getId(),
                'quote_state' => $quoteState,
                'quote_message' => $message,
            ],
        ];
    }

    private function getStoredIfoodQuoteState(Order $order): array
    {
        $otherInformations = $this->getDecodedOrderOtherInformations($order);
        if ($otherInformations === []) {
            return [];
        }

        $storedState = $otherInformations[self::APP_CONTEXT]
            ?? $otherInformations['logistics']
            ?? [];

        if (is_object($storedState)) {
            $storedState = (array) $storedState;
        }

        if (is_array($storedState) && is_array($storedState['quote'] ?? null)) {
            return $storedState['quote'];
        }

        return is_array($storedState) ? $storedState : [];
    }

    private function resolveIfoodQuoteStateFromStatus(int $statusCode): string
    {
        return in_array($statusCode, [400, 401, 403, 404, 409, 422], true) ? 'unavailable' : 'error';
    }

    private function normalizeIfoodMoneyValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function normalizeIfoodQuoteEta(array $quoteResponse): ?string
    {
        $deliveryTime = is_array($quoteResponse['deliveryTime'] ?? null)
            ? $quoteResponse['deliveryTime']
            : [];
        $min = isset($deliveryTime['min']) ? (int) $deliveryTime['min'] : 0;
        $max = isset($deliveryTime['max']) ? (int) $deliveryTime['max'] : 0;

        if ($min <= 0 && $max <= 0) {
            return null;
        }

        if ($min > 0 && $max > 0) {
            return sprintf('%d - %d min', (int) ceil($min / 60), (int) ceil($max / 60));
        }

        $value = $min > 0 ? $min : $max;

        return sprintf('%d min', (int) ceil($value / 60));
    }

    private function resolveIfoodPeopleDisplayName(?People $people): string
    {
        if (!$people instanceof People) {
            return '';
        }

        $name = trim((string) ($people->getAlias() ?: $people->getName() ?: ''));

        return $this->truncateIfoodText($name, 50);
    }

    private function resolveIfoodPeoplePhone(?People $people): ?array
    {
        if (!$people instanceof People) {
            return null;
        }

        foreach ($people->getPhone() as $phone) {
            if (!$phone instanceof Phone) {
                continue;
            }

            $countryCode = $this->normalizeDigits((string) $phone->getDdi());
            $areaCode = $this->normalizeDigits((string) $phone->getDdd());
            $number = $this->normalizeDigits((string) $phone->getPhone());

            if ($number === '') {
                continue;
            }

            return [
                'countryCode' => $countryCode !== '' ? $countryCode : '55',
                'areaCode' => $areaCode !== '' ? $areaCode : '00',
                'number' => $number,
            ];
        }

        return null;
    }

    private function truncateIfoodText(string $value, int $limit): string
    {
        $normalized = trim($value);
        if ($normalized === '' || $limit <= 0) {
            return $normalized;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($normalized, 0, $limit);
        }

        return substr($normalized, 0, $limit);
    }

    // ENTREGA
    // Define endereço de entrega e, se entrega for por terceiros, cria taxa de entrega
    private function addDelivery(Order &$order, array $orderDetails)
    {
        $delivery = is_array($orderDetails['delivery'] ?? null) ? $orderDetails['delivery'] : [];
        $deliveryAddress = is_array($delivery['deliveryAddress'] ?? null) ? $delivery['deliveryAddress'] : [];
        if (!$deliveryAddress) {
            return;
        }

        if ($this->normalizeString($delivery['deliveredBy'] ?? null) !== 'MERCHANT') {
            $this->addDeliveryFee($order, $orderDetails['total']);
        }

        $country = $this->resolveIfoodCountryEntity($deliveryAddress['country'] ?? null);
        $countryCode = $country instanceof \ControleOnline\Entity\Country
            ? $country->getCountrycode()
            : 'BR';
        $stateCode = $this->resolveIfoodAddressStateCode($country, $deliveryAddress['state'] ?? null);
        $postalCodeDigits = preg_replace('/\D+/', '', (string) ($deliveryAddress['postalCode'] ?? ''));
        $postalCode = $postalCodeDigits !== '' && $postalCodeDigits !== '0'
            ? (int) $postalCodeDigits
            : 0;
        if ($postalCode <= 0) {
            $postalCode = 99999998;
        }

        $streetName = $this->normalizeString($deliveryAddress['streetName'] ?? null);
        if ($streetName === '') {
            $formattedAddress = $this->normalizeString($deliveryAddress['formattedAddress'] ?? null);
            if ($formattedAddress !== '') {
                $streetName = trim((string) preg_replace('/,\s*.+$/', '', $formattedAddress));
            }
        }

        $neighborhood = $this->normalizeString($deliveryAddress['neighborhood'] ?? null);
        $city = $this->normalizeString($deliveryAddress['city'] ?? null);
        $reference = $this->normalizeString($deliveryAddress['reference'] ?? null);
        $streetNumberPayload = $this->resolveIfoodStreetNumberPayload($deliveryAddress);
        $latitude = (float) ($deliveryAddress['coordinates']['latitude'] ?? 0);
        $longitude = (float) ($deliveryAddress['coordinates']['longitude'] ?? 0);

        self::$logger->info('iFood delivery discovery started', [
            'local_order_id' => $order->getId(),
            'client_id' => $order->getClient()?->getId(),
            'formatted_address' => $this->normalizeString($deliveryAddress['formattedAddress'] ?? null),
            'street_name' => $streetName,
            'street_number_raw' => $streetNumberPayload['street_number_raw'] ?? '',
            'street_number' => $streetNumberPayload['street_number'] ?? null,
            'neighborhood' => $neighborhood,
            'city' => $city,
            'state_input' => $this->normalizeString($deliveryAddress['state'] ?? null),
            'state_resolved' => $stateCode,
            'country_input' => $this->normalizeString($deliveryAddress['country'] ?? null),
            'country_resolved' => $countryCode,
            'postal_code_input' => $this->normalizeString($deliveryAddress['postalCode'] ?? null),
            'postal_code_resolved' => $postalCode,
            'reference' => $reference,
            'complement_resolved' => $streetNumberPayload['complement'] ?? '',
        ]);

        $deliveryAddressEntity = $this->addressService->discoveryAddress(
            $order->getClient(),
            $postalCode,
            $streetNumberPayload['street_number'] ?? null,
            $streetName !== '' ? $streetName : 'Endereço não informado',
            $neighborhood !== '' ? $neighborhood : 'Sem bairro',
            $city !== '' ? $city : 'Sem cidade',
            $stateCode !== '' ? $stateCode : 'NI',
            $countryCode,
            $streetNumberPayload['complement'] ?? '',
            (int) round($latitude),
            (int) round($longitude),
            $reference !== '' ? $reference : 'Default',
        );

        $deliveryAddressEntity->setLatitude($latitude);
        $deliveryAddressEntity->setLongitude($longitude);
        $order->setAddressDestination($deliveryAddressEntity);
        $this->entityManager->persist($deliveryAddressEntity);

        self::$logger->info('iFood delivery discovery resolved address entity', [
            'local_order_id' => $order->getId(),
            'client_id' => $order->getClient()?->getId(),
            'address_id' => $deliveryAddressEntity->getId(),
            'street' => $deliveryAddressEntity->getStreet()?->getStreet(),
            'number' => $deliveryAddressEntity->getNumber(),
            'complement' => $deliveryAddressEntity->getComplement(),
            'district' => $deliveryAddressEntity->getStreet()?->getDistrict()?->getDistrict(),
            'city' => $deliveryAddressEntity->getStreet()?->getDistrict()?->getCity()?->getCity(),
            'state' => $deliveryAddressEntity->getStreet()?->getDistrict()?->getCity()?->getState()?->getUf(),
            'postal_code' => $deliveryAddressEntity->getStreet()?->getCep()?->getCep(),
        ]);
    }

    // TAXA DE ENTREGA
    // Cria fatura para taxa de entrega (cobrada do restaurante para o iFood)
    private function addDeliveryFee(Order &$order, array $payments)
    {
        $deliveryFee = round((float) ($payments['deliveryFee'] ?? 0), 2);
        if ($deliveryFee <= 0) {
            return;
        }

        $providerWallet = $this->walletService->discoverWallet($order->getProvider(), self::$app);
        $ifoodWallet = $this->walletService->discoverWallet(self::$foodPeople, self::$app);
        $status = $this->statusService->discoveryStatus('closed', 'paid', 'invoice');
        $paymentType = $this->resolveIfoodSettlementPaymentType($order->getProvider(), $providerWallet);
        $order->setRetrieveContact(self::$foodPeople);

        $this->createIfoodPayableInvoice(
            $order,
            $paymentType,
            $deliveryFee,
            $status,
            $providerWallet,
            $ifoodWallet,
            'delivery_fee',
            [
                'component_value' => $deliveryFee,
            ]
        );
    }

    // TAXAS ADICIONAIS
    // Cria fatura para taxas/comissões do iFood
    private function addFees(Order $order, array $payments)
    {
        $additionalFees = round((float) ($payments['additionalFees'] ?? 0), 2);
        if ($additionalFees <= 0) {
            return;
        }

        $status = $this->statusService->discoveryStatus('closed', 'paid', 'invoice');
        $providerWallet = $this->walletService->discoverWallet($order->getProvider(), self::$app);
        $ifoodWallet = $this->walletService->discoverWallet(self::$foodPeople, self::$app);
        $paymentType = $this->resolveIfoodSettlementPaymentType($order->getProvider(), $providerWallet);
        $this->createIfoodPayableInvoice(
            $order,
            $paymentType,
            $additionalFees,
            $status,
            $providerWallet,
            $ifoodWallet,
            'marketplace_fee',
            [
                'component_value' => $additionalFees,
            ]
        );
    }

    // PAGAMENTOS
    // Agrupa todas as operações de pagamento: faturas de recebimento e taxas
    private function addPayments(Order $order, array $orderDetails)
    {
        $this->addReceiveInvoices($order, $orderDetails['payments']['methods']);
        $this->addFees($order, $orderDetails['total']);
    }

    // PRODUTOS
    // Percorre itens do pedido recursivamente, criando produtos e relacionamentos
    // Trata produtos, opções e customizações como componentes hierárquicos
    private function addProducts(Order $order, array $items, ?Product $parentProduct = null, ?OrderProduct $orderParentProduct = null, ?string $productType = 'product')
    {
        foreach ($items as $item) {

            if ((isset($item['options']) && $item['options']) || (isset($item['customizations']) && $item['customizations']))
                $productType = 'custom';

            $product = $this->discoveryProduct($order, $item, $parentProduct, $productType);
            $productGroup = null;
            if (isset($item['groupName']))
                $productGroup = $this->productGroupService->discoveryProductGroup($parentProduct ?: $product, $item['groupName']);
            $orderProduct = $this->orderProductService->addOrderProduct($order, $product, $item['quantity'], $item['unitPrice'], $productGroup, $parentProduct, $orderParentProduct);
            $this->syncOrderProductComment($orderProduct, $this->extractItemRemark($item));
            if (isset($item['options']) && $item['options'])
                $this->addProducts($order, $item['options'], $product, $orderProduct, 'component');
            if (isset($item['customizations']) && $item['customizations'])
                $this->addProducts($order, $item['customizations'], $product, $orderProduct, 'component');
        }
    }

    // TOKEN OAUTH
    // Autentica na API do iFood e retorna token de acesso
    private function getAccessToken(): ?string
    {
        $cachedToken = self::$authTokenCache['token'] ?? null;
        $cachedExpiresAt = self::$authTokenCache['expires_at'] ?? 0;
        if (is_string($cachedToken) && $cachedToken !== '' && (int) $cachedExpiresAt > (time() + 30)) {
            return $cachedToken;
        }

        try {
            $response = $this->httpClient->request('POST', self::API_BASE_URL . '/authentication/v1.0/oauth/token', [
                'headers' => ['content-type' => 'application/x-www-form-urlencoded'],
                'body' => http_build_query([
                    'grantType' => 'client_credentials',
                    'clientId' => $_ENV['OAUTH_IFOOD_CLIENT_ID'] ?? '',
                    'clientSecret' => $_ENV['OAUTH_IFOOD_CLIENT_SECRET'] ?? '',
                ]),
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getContent(false);

            if ($statusCode !== 200) {
                self::$logger->error('iFood access token request failed', [
                    'status' => $statusCode,
                    'response' => $responseBody,
                ]);
                return null;
            }

            $data = $response->toArray(false);
            $token = $this->normalizeString($data['accessToken'] ?? null);
            if ($token === '') {
                self::$logger->error('iFood access token is missing in response', [
                    'response' => $responseBody,
                ]);
                return null;
            }

            $expiresIn = isset($data['expiresIn']) && is_numeric($data['expiresIn'])
                ? max(0, (int) $data['expiresIn'])
                : 300;

            self::$authTokenCache = [
                'token' => $token,
                'expires_at' => time() + $expiresIn,
            ];

            return $token;
        } catch (\Throwable $e) {
            self::$logger->error('iFood access token request error', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // FETCH DETALHES DO PEDIDO
    // Chama API do iFood para buscar informa��es completas do pedido (cliente, produtos, entrega, pagamentos)
    private function fetchOrderDetails(string $orderId): ?array
    {
        try {
            $encodedOrderId = rawurlencode($orderId);
            $endpoint = self::API_BASE_URL . '/order/v1.0/orders/' . $encodedOrderId;
            $token = $this->getAccessToken();
            if (!$token) {
                self::$logger->warning('iFood order details request skipped because token is unavailable', [
                    'order_id' => $orderId,
                    'endpoint' => $endpoint,
                ]);

                return null;
            }

            try {
                $response = $this->httpClient->request('GET', $endpoint, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                    ],
                ]);

                $statusCode = $response->getStatusCode();
                $rawBody = $response->getContent(false);

                if ($statusCode !== 200) {
                    self::$logger->warning('iFood order details request returned non-success status', [
                        'order_id' => $orderId,
                        'endpoint' => $endpoint,
                        'status' => $statusCode,
                        'response' => $rawBody,
                    ]);

                    if ($statusCode === 401) {
                        // Force token refresh on the next independent lookup.
                        self::$authTokenCache = [];
                    }

                    return null;
                }

                $data = $this->decodeIfoodActionResponseBody((string) $rawBody);
                return is_array($data) ? $data : null;
            } catch (\Throwable $e) {
                self::$logger->warning('iFood order details request endpoint error', [
                    'order_id' => $orderId,
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        } catch (\Throwable $e) {
            self::$logger->error('iFood order details request error', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    // DESCOBERTA/CRIAÇÃO DO CLIENTE
    // Busca cliente existente pelo ID do iFood ou cria novo com dados do pedido
    private function discoveryClient(People $provider, array $customerData): ?People
    {
        $customerName = $this->normalizeString($customerData['name'] ?? null);
        $codClienteiFood = $this->normalizeString($customerData['id'] ?? null);
        $document = $this->resolveCustomerDocumentNumber($customerData);
        $phone = $this->resolveCustomerPhoneForDiscovery($customerData);

        self::$logger->info('iFood client discovery started', [
            'provider_id' => $provider->getId(),
            'ifood_customer_id' => $codClienteiFood,
            'customer_name' => $customerName,
            'document' => $document,
            'has_phone_for_discovery' => !empty($phone),
            'raw_phone_number' => $this->normalizeString($customerData['phone']['number'] ?? null),
            'raw_phone_localizer' => $this->normalizeString($customerData['phone']['localizer'] ?? null),
        ]);

        if ($customerName === '' && $document === null && $codClienteiFood === '') {
            self::$logger->warning('Dados do cliente incompletos', ['customer' => $customerData]);
            return null;
        }

        $documentType = $this->resolveCustomerDocumentType($customerData, $document);
        $clientByCode = $codClienteiFood !== '' ? $this->findEntityByExtraData('People', 'code', $codClienteiFood, People::class) : null;
        $clientByDocument = null;
        $client = null;

        if ($clientByCode instanceof People) {
            self::$logger->info('iFood client discovery matched by remote code', [
                'ifood_customer_id' => $codClienteiFood,
                'people_id' => $clientByCode->getId(),
            ]);
        }

        if ($document !== null) {
            try {
                $documentEntity = $this->peopleService->getDocument($document, $documentType);
                $clientByDocument = $documentEntity?->getPeople();
                if ($clientByDocument instanceof People) {
                    self::$logger->info('iFood client discovery matched by document', [
                        'ifood_customer_id' => $codClienteiFood,
                        'people_id' => $clientByDocument->getId(),
                        'document' => $document,
                    ]);
                }
            } catch (\Throwable $exception) {
                self::$logger->warning('iFood client document lookup failed', [
                    'ifood_customer_id' => $codClienteiFood,
                    'document' => $document,
                    'document_type' => $documentType,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($clientByCode instanceof People) {
            $client = $clientByCode;
        }

        if (!$client instanceof People && $clientByDocument instanceof People) {
            $client = $clientByDocument;
        }

        if (!$client instanceof People) {
            try {
                $client = $this->peopleService->discoveryPeople(null, null, $phone, $customerName !== '' ? $customerName : null);
                if ($client instanceof People) {
                    self::$logger->info('iFood client discovery resolved via standard discoveryPeople', [
                        'ifood_customer_id' => $codClienteiFood,
                        'people_id' => $client->getId(),
                        'document' => $document,
                        'used_phone' => !empty($phone),
                    ]);
                }
            } catch (\Throwable $exception) {
                self::$logger->warning('iFood client standard discovery failed', [
                    'ifood_customer_id' => $codClienteiFood,
                    'customer_name' => $customerName,
                    'document' => $document,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if (!$client instanceof People && $customerName !== '') {
            $client = $this->peopleService->discoveryPeople(null, null, null, $customerName);
            if ($client instanceof People) {
                self::$logger->info('iFood client discovery fell back to name-only lookup', [
                    'ifood_customer_id' => $codClienteiFood,
                    'people_id' => $client->getId(),
                    'customer_name' => $customerName,
                ]);
            }
        }

        if (!$client instanceof People) {
            self::$logger->warning('iFood client could not be resolved after discovery attempts', [
                'ifood_customer_id' => $codClienteiFood,
                'customer_name' => $customerName,
                'document' => $document,
            ]);
            return null;
        }

        if ($clientByCode instanceof People && $document !== null && $clientByCode->getId() !== $client->getId()) {
            self::$logger->warning('iFood client mismatch detected between code and document mapping', [
                'ifood_customer_id' => $codClienteiFood,
                'people_by_code' => $clientByCode->getId(),
                'people_by_document' => $client->getId(),
                'document' => $document,
            ]);
        }

        return $this->syncIfoodClientData(
            $client,
            $provider,
            $customerName,
            $phone,
            $document,
            $documentType,
            $codClienteiFood
        );
    }

    // DESCOBERTA/CRIAÇÃO DO PRODUTO
    // Busca produto existente por múltiplas chaves (iFood ID, código externo, EAN, nome)
    // Se não encontrar, cria novo produto. Se tem pai, associa como grupo/componente
    private function discoveryProduct(Order $order, array $item, ?Product $parentProduct = null, string $productType = 'product'): Product
    {
        $codProductiFood = $item['id'];
        $product = $this->findEntityByExtraData('Product', 'code', $codProductiFood, Product::class);

        if (!$product && !empty($item['externalCode']))
            $product = $this->entityManager->getRepository(Product::class)->findOneBy([
                'company' => $order->getProvider(),
                'id' => $item['externalCode']
            ]);

        if (!$product && !empty($item['ean']))
            $product = $this->entityManager->getRepository(Product::class)->findOneBy([
                'company' => $order->getProvider(),
                'sku' => $item['ean']
            ]);

        if (!$product)
            $product = $this->entityManager->getRepository(Product::class)->findOneBy(['company' => $order->getProvider(), 'product' => $item['name']]);

        if (!$product) {
            $productUnity = $this->entityManager->getRepository(ProductUnity::class)->findOneBy(['productUnit' => 'UN']);

            $product = new Product();
            $product->setProduct($item['name']);
            $product->setSku(empty($item['ean']) ? null : $item['ean']);
            $product->setPrice($item['unitPrice']);
            $product->setProductUnit($productUnity);
            $product->setType($productType);
            $product->setProductCondition('new');
            $product->setCompany($order->getProvider());

            $this->entityManager->persist($product);
            $this->entityManager->flush();
            if ($parentProduct && isset($item['groupName'])) {
                $productGroup = $this->productGroupService->discoveryProductGroup($parentProduct, $item['groupName']);
                $quantity = (float) ($item['quantity'] ?? 1);
                $productGroupProduct = $this->entityManager
                    ->getRepository(ProductGroupProduct::class)
                    ->findSharedGroupItem($productGroup, $product, $productType, $quantity);

                if (!$productGroupProduct instanceof ProductGroupProduct) {
                    $productGroupProduct = new ProductGroupProduct();
                    $productGroupProduct->setProductChild($product);
                    $productGroupProduct->setProductType($productType);
                    $productGroupProduct->setProductGroup($productGroup);
                    $productGroupProduct->setProduct($productType === 'feedstock' ? $parentProduct : null);
                    $this->entityManager->persist($productGroupProduct);
                }

                $productGroupProduct->setQuantity($quantity);
                $productGroupProduct->setPrice((float) ($item['unitPrice'] ?? 0));
                $productGroupProduct->setProduct($productType === 'feedstock' ? $parentProduct : null);
                $this->entityManager->flush();
            }
        }

        return $this->discoveryFoodCode($product, $codProductiFood);
    }

    // ESCUTA DE MUDANÇAS DE ENTIDADE
    // Registra a classe como listener de eventos de mudança de entidade
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
            'name' => strtolower($this->normalizeString($action['name'] ?? null)),
            'requested_at' => $this->normalizeString($action['requested_at'] ?? null),
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

        return $oldStatusId !== $statusOutId
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

    private function shouldAutoReadyFromQueue(Order $order): bool
    {
        $realStatus = strtolower(trim((string) ($order->getStatus()?->getRealStatus() ?? '')));
        $status = strtolower(trim((string) ($order->getStatus()?->getStatus() ?? '')));

        if ($realStatus === 'open') {
            return true;
        }

        if ($realStatus !== 'pending' || $status !== 'ready') {
            return false;
        }

        $state = $this->getStoredOrderIntegrationState($order);
        $remoteState = strtolower($this->normalizeString($state['remote_order_state'] ?? null));
        $lastAction = strtolower($this->normalizeString($state['last_action'] ?? null));
        $lastActionErrno = $this->normalizeString($state['last_action_errno'] ?? null);

        if (in_array($remoteState, ['ready', 'dispatching', 'dispatched', 'order_dispatched'], true)) {
            return false;
        }

        return !($lastAction === 'ready' && $lastActionErrno === '0');
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

        if (!$this->shouldAutoReadyFromQueue($order) || !$this->areAllOrderProductQueuesReady($order)) {
            return;
        }

        $this->performReadyAction($order);
    }

    // HANDLER DE MUDANÇA DE ENTIDADE
    // Quando um pedido do iFood muda de status, dispara sincronização com o iFood
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
        if ($entity->getApp() !== self::$app)
            return;

        $actionChanged = $this->hasPendingOrderActionChanged($oldEntity, $entity);

        if ($actionChanged)
            $this->changeStatus($entity);
    }

    private function resolveRemoteOrderId(Order $order): ?string
    {
        $orderId = '';
        try {
            $orderId = $this->normalizeString($this->discoveryFoodCodeByEntity($order));
        } catch (\Throwable $e) {
            self::$logger->warning('iFood remote order id lookup via extraDataService failed, using fallback state', [
                'local_order_id' => $order->getId(),
                'error' => $e->getMessage(),
            ]);
        }

        if ($orderId !== '') {
            return $orderId;
        }

        $storedState = $this->getStoredOrderIntegrationState($order);
        $orderId = $this->normalizeString($storedState['ifood_id'] ?? null);
        if ($orderId !== '') {
            return $orderId;
        }

        $orderCode = $this->normalizeString($storedState['ifood_code'] ?? null);
        if ($orderCode !== '') {
            return $orderCode;
        }

        return null;
    }

    private function normalizeActionResponse(?array $rawResponse): array
    {
        if (!$rawResponse) {
            return [
                'errno' => 1,
                'errmsg' => 'Nao foi possivel executar a acao no iFood.',
            ];
        }

        $statusCode = (int) ($rawResponse['status'] ?? 0);
        $body = is_array($rawResponse['body'] ?? null) ? $rawResponse['body'] : [];
        $isSuccess = $statusCode >= 200 && $statusCode < 300;

        $message = $this->normalizeString(
            $body['message']
                ?? $body['details']
                ?? $body['description']
                ?? ($body['error']['message'] ?? null)
        );

        if ($message === '') {
            $message = $isSuccess ? 'ok' : 'HTTP ' . ($statusCode > 0 ? $statusCode : 500);
        }

        return [
            'errno' => $isSuccess ? 0 : ($statusCode > 0 ? $statusCode : 1),
            'errmsg' => $message,
            'status' => $statusCode,
            'data' => $body,
        ];
    }

    private function persistOrderActionResult(
        Order $order,
        string $action,
        ?array $rawResponse,
        ?string $remoteStateOnSuccess = null,
        ?array $localStatusOnSuccess = null
    ): array {
        $result = $this->normalizeActionResponse($rawResponse);
        $isSuccess = (string) ($result['errno'] ?? '') === '0';

        $payload = [
            'last_action' => $action,
            'last_action_at' => date('Y-m-d H:i:s'),
            'last_action_errno' => isset($result['errno']) ? (string) $result['errno'] : '',
            'last_action_message' => $this->normalizeString($result['errmsg'] ?? null),
        ];

        if ($isSuccess && $remoteStateOnSuccess !== null && $remoteStateOnSuccess !== '') {
            $payload['remote_order_state'] = $remoteStateOnSuccess;
        }

        try {
            if ($isSuccess && is_array($localStatusOnSuccess)) {
                $this->applyLocalStatus(
                    $order,
                    (string) ($localStatusOnSuccess['realStatus'] ?? ''),
                    (string) ($localStatusOnSuccess['status'] ?? '')
                );
            }

            $this->persistOrderIntegrationState($order, $payload);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            self::$logger->error('iFood order action state persist failed', [
                'order_id' => $order->getId(),
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return [
                'errno' => 1,
                'errmsg' => 'Falha ao persistir estado da acao no iFood.',
                'status' => (int) ($result['status'] ?? 500),
                'data' => is_array($result['data'] ?? null) ? $result['data'] : [],
            ];
        }

        return $result;
    }

    private function buildUnavailableOrderActionResponse(string $message): array
    {
        return [
            'errno' => 10002,
            'errmsg' => $message,
        ];
    }

    public function performCancelAction(Order $order, ?string $reason = null, ?string $cancellationCode = null): array
    {
        $this->init();
        $orderId = $this->resolveRemoteOrderId($order);
        if (!$orderId) {
            return $this->buildUnavailableOrderActionResponse('Pedido iFood sem identificador remoto.');
        }

        $normalizedReason = $this->normalizeString($reason);
        $normalizedCancellationCode = $this->normalizeString($cancellationCode);
        if ($normalizedCancellationCode === '') {
            $normalizedCancellationCode = $this->resolveDefaultIfoodCancellationCode($orderId) ?? '';
        }

        $result = $this->persistOrderActionResult(
            $order,
            'cancel',
            $this->cancelByShop(
                $orderId,
                $normalizedCancellationCode !== '' ? $normalizedCancellationCode : null
            ),
            'cancellation_requested'
        );

        if ((string) ($result['errno'] ?? '') === '0' && ($normalizedReason !== '' || $normalizedCancellationCode !== '')) {
            try {
                $this->persistOrderIntegrationState($order, [
                    'cancel_reason' => $normalizedCancellationCode !== '' ? $normalizedCancellationCode : $normalizedReason,
                ]);
                $this->entityManager->flush();
            } catch (\Throwable $e) {
                self::$logger->error('iFood cancel reason persist failed', [
                    'order_id' => $order->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    public function respondHandshakeDispute(Order $order, string $decision, ?string $reason = null, ?string $alternativeId = null): array
    {
        $this->init();

        $storedState = $this->getStoredOrderIntegrationState($order);
        $disputeId = $this->normalizeString($storedState['handshake_dispute_id'] ?? null);
        if ($disputeId === '') {
            return $this->buildUnavailableOrderActionResponse('Pedido iFood sem negociacao aberta.');
        }

        $normalizedDecision = strtolower($this->normalizeString($decision));
        if (!in_array($normalizedDecision, ['accept', 'reject', 'alternative'], true)) {
            return $this->buildUnavailableOrderActionResponse('Acao de negociacao iFood invalida.');
        }

        $validNegotiationReasons = [
            'HIGH_STORE_DEMAND',
            'UNKNOWN_ISSUE',
            'CUSTOMER_SATISFACTION',
            'INVENTORY_CHECK',
            'SYSTEM_ISSUE',
            'WRONG_ORDER',
            'PRODUCT_QUALITY',
            'LATE_DELIVERY',
            'CUSTOMER_REQUEST',
        ];
        $payload = [];
        $normalizedReason = $this->normalizeString($reason);
        $normalizedReasonCode = strtoupper($normalizedReason);
        $normalizedAlternativeId = $this->normalizeString($alternativeId);

        if ($normalizedDecision === 'accept') {
            $acceptReasons = array_filter(array_map(
                static fn($value) => strtoupper(trim((string) $value)),
                explode(',', (string) ($storedState['handshake_accept_reasons'] ?? ''))
            ));
            if ($acceptReasons !== []) {
                $payload['reason'] = in_array($normalizedReasonCode, $acceptReasons, true)
                    ? $normalizedReasonCode
                    : reset($acceptReasons);
            }
        }

        if ($normalizedDecision === 'reject') {
            if (!in_array($normalizedReasonCode, $validNegotiationReasons, true)) {
                return $this->buildUnavailableOrderActionResponse('Informe um motivo valido para rejeitar a negociacao iFood.');
            }

            $payload['reason'] = $normalizedReasonCode;
        }

        if ($normalizedDecision === 'alternative') {
            $storedAlternatives = $this->decodeOrderOtherInformationsValue($storedState['handshake_alternatives_json'] ?? null);
            $selectedAlternative = [];
            $selectedAlternativeId = $this->normalizeString($storedState['handshake_alternative_id'] ?? null);
            foreach ($storedAlternatives as $alternative) {
                if (!is_array($alternative)) {
                    continue;
                }

                $currentAlternativeId = $this->normalizeString($alternative['id'] ?? null);
                if ($selectedAlternative === [] || ($normalizedAlternativeId !== '' && $currentAlternativeId === $normalizedAlternativeId)) {
                    $selectedAlternative = $alternative;
                    $selectedAlternativeId = $currentAlternativeId !== '' ? $currentAlternativeId : $selectedAlternativeId;
                }
                if ($normalizedAlternativeId !== '' && $currentAlternativeId === $normalizedAlternativeId) {
                    break;
                }
            }

            $alternativeMetadata = is_array($selectedAlternative['metadata'] ?? null)
                ? $selectedAlternative['metadata']
                : [];
            $alternativeAmount = is_array($alternativeMetadata['maxAmount'] ?? null)
                ? $alternativeMetadata['maxAmount']
                : (is_array($alternativeMetadata['amount'] ?? null) ? $alternativeMetadata['amount'] : []);
            $alternativeTimes = is_array($alternativeMetadata['allowedsAdditionalTimeInMinutes'] ?? null)
                ? $alternativeMetadata['allowedsAdditionalTimeInMinutes']
                : (is_array($alternativeMetadata['allowedAdditionalTimeInMinutes'] ?? null) ? $alternativeMetadata['allowedAdditionalTimeInMinutes'] : []);
            $alternativeReasons = is_array($alternativeMetadata['allowedsAdditionalTimeReasons'] ?? null)
                ? $alternativeMetadata['allowedsAdditionalTimeReasons']
                : (is_array($alternativeMetadata['allowedAdditionalTimeReasons'] ?? null) ? $alternativeMetadata['allowedAdditionalTimeReasons'] : []);

            $alternativeType = strtoupper($this->normalizeString($selectedAlternative['type'] ?? ($storedState['handshake_alternative_type'] ?? null)));
            $payload['type'] = $alternativeType;
            $payload['metadata'] = [];

            if (in_array($alternativeType, ['REFUND', 'BENEFIT'], true)) {
                $amountValue = $this->normalizeString($alternativeAmount['value'] ?? ($storedState['handshake_alternative_amount_value'] ?? null));
                $amountCurrency = $this->normalizeString($alternativeAmount['currency'] ?? ($storedState['handshake_alternative_amount_currency'] ?? null)) ?: 'BRL';
                if ($amountValue !== '') {
                    $payload['metadata']['amount'] = [
                        'value' => $amountValue,
                        'currency' => $amountCurrency,
                    ];
                }
            }

            if ($alternativeType === 'ADDITIONAL_TIME') {
                $minutes = (int) $this->normalizeString($alternativeTimes[0] ?? ($storedState['handshake_alternative_time_minutes'] ?? null));
                $timeReason = $normalizedReason !== ''
                    ? $normalizedReason
                    : $this->normalizeString($alternativeReasons[0] ?? ($storedState['handshake_alternative_reason'] ?? null));
                if ($minutes > 0 && $timeReason !== '') {
                    $payload['metadata']['additionalTimeInMinutes'] = $minutes;
                    $payload['metadata']['additionalTimeReason'] = $timeReason;
                }
            }

            if ($payload['type'] === '' || $payload['metadata'] === []) {
                if (in_array($alternativeType, ['REFUND', 'BENEFIT'], true)) {
                    return $this->buildUnavailableOrderActionResponse('Pedido iFood sem valor permitido para contraproposta de reembolso.');
                }

                if ($alternativeType === 'ADDITIONAL_TIME') {
                    return $this->buildUnavailableOrderActionResponse('Pedido iFood sem tempo permitido para contraproposta.');
                }

                return $this->buildUnavailableOrderActionResponse('Pedido iFood sem alternativa valida para contraproposta.');
            }

            if ($selectedAlternativeId === '') {
                return $this->buildUnavailableOrderActionResponse('Pedido iFood sem identificador da alternativa para contraproposta.');
            }
        }

        $actionPath = $normalizedDecision === 'alternative'
            ? '/alternatives/' . rawurlencode($selectedAlternativeId)
            : '/' . $normalizedDecision;

        return $this->persistOrderActionResult(
            $order,
            'handshake_' . $normalizedDecision,
            $this->callIfoodDisputeAction($disputeId, $actionPath, $payload),
            null,
            null
        );
    }

    public function performReadyAction(Order $order): array
    {
        $this->init();
        $orderId = $this->resolveRemoteOrderId($order);
        if (!$orderId) {
            return $this->buildUnavailableOrderActionResponse('Pedido iFood sem identificador remoto.');
        }

        $storedState = $this->getStoredOrderIntegrationState($order);
        $remoteState = strtolower($this->normalizeString($storedState['remote_order_state'] ?? null));
        $shouldAutoConfirmBeforeDispatch = in_array($remoteState, [
            '',
            'new',
            'placed',
            'order_created',
            'pending',
        ], true);

        if ($shouldAutoConfirmBeforeDispatch) {
            $confirmResult = $this->persistOrderActionResult(
                $order,
                'confirm',
                $this->confirmOrder($orderId),
                'confirmed',
                ['realStatus' => 'open', 'status' => 'preparing']
            );

            if ((string) ($confirmResult['errno'] ?? '') !== '0') {
                return [
                    'errno' => $confirmResult['errno'] ?? 1,
                    'errmsg' => 'Falha ao confirmar pedido antes do despacho: ' . $this->normalizeString($confirmResult['errmsg'] ?? null),
                    'status' => (int) ($confirmResult['status'] ?? 500),
                    'data' => is_array($confirmResult['data'] ?? null) ? $confirmResult['data'] : [],
                ];
            }
        }

        $dispatchFlow = $this->resolveDispatchFlowForOrder($order);
        $isMerchantDelivery = $dispatchFlow === 'merchant';
        $isPickupFlow = $dispatchFlow === 'pickup';
        $stateOnSuccess = $isMerchantDelivery ? 'dispatching' : 'ready';
        $localStatusOnSuccess = $isMerchantDelivery
            ? ['realStatus' => 'pending', 'status' => 'way']
            : ['realStatus' => 'pending', 'status' => 'ready'];
        $actionResponse = ($isPickupFlow || !$isMerchantDelivery)
            ? $this->readyOrder($orderId)
            : $this->dispatchOrderByDeliveryMode($order, $orderId);

        return $this->persistOrderActionResult(
            $order,
            'ready',
            $actionResponse,
            $stateOnSuccess,
            $localStatusOnSuccess
        );
    }

    public function performDeliveredAction(Order $order, ?string $deliveryCode = null, ?string $locator = null): array
    {
        $this->init();
        $orderId = $this->resolveRemoteOrderId($order);
        if (!$orderId) {
            return $this->buildUnavailableOrderActionResponse('Pedido iFood sem identificador remoto.');
        }

        $dispatchFlow = $this->resolveDispatchFlowForOrder($order);
        $storedState = $this->getStoredOrderIntegrationState($order);
        $remoteState = strtolower($this->normalizeString($storedState['remote_order_state'] ?? null));
        $realStatus = strtolower($this->normalizeString($order->getStatus()?->getRealStatus()));
        $statusName = strtolower($this->normalizeString($order->getStatus()?->getStatus()));
        $alreadyDispatched = $dispatchFlow === 'merchant'
            && (
                ($realStatus === 'pending' && $statusName === 'way')
                || in_array($remoteState, ['dispatching', 'dispatched', 'order_dispatched'], true)
            );

        if ($alreadyDispatched) {
            $normalizedDeliveryCode = $this->normalizeString($deliveryCode);
            if ($normalizedDeliveryCode === '') {
                return $this->buildUnavailableOrderActionResponse(
                    'Entrega propria iFood deve ser concluida pelo link de confirmacao ou por codigo de entrega valido.'
                );
            }

            return $this->persistOrderActionResult(
                $order,
                'delivered',
                $this->verifyDeliveryCode($orderId, $normalizedDeliveryCode),
                'concluded',
                ['realStatus' => 'closed', 'status' => 'closed']
            );
        }

        return $this->persistOrderActionResult(
            $order,
            'delivered',
            $this->dispatchOrderByDeliveryMode($order, $orderId),
            'dispatching'
        );
    }

    public function performStartPreparationAction(Order $order): array
    {
        $this->init();
        $orderId = $this->resolveRemoteOrderId($order);
        if (!$orderId) {
            return $this->buildUnavailableOrderActionResponse('Pedido iFood sem identificador remoto.');
        }

        return $this->persistOrderActionResult(
            $order,
            'start_preparation',
            $this->callIfoodOrderAction($orderId, '/startPreparation'),
            'preparing',
            ['realStatus' => 'open', 'status' => 'preparing']
        );
    }

    private function decodeIfoodActionResponseBody(string $rawBody): array
    {
        if ($rawBody === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return ['message' => $rawBody];
    }

    private function normalizeIfoodRequestPayload(array $payload): mixed
    {
        if (empty($payload)) {
            return (object) [];
        }

        return $payload;
    }

    private function callIfoodOrderAction(string $orderId, string $actionPath, array $payload = []): ?array
    {
        try {
            $token = $this->getAccessToken();
            if (!$token) {
                self::$logger->warning('iFood order action skipped because token is unavailable', [
                    'order_id' => $orderId,
                    'action' => $actionPath,
                ]);
                return null;
            }

            $encodedOrderId = rawurlencode($orderId);
            $endpoint = self::API_BASE_URL . '/order/v1.0/orders/' . $encodedOrderId . $actionPath;

            try {
                self::$logger->info('iFood order action request', [
                    'order_id' => $orderId,
                    'action' => $actionPath,
                    'payload' => $payload,
                ]);

                $response = $this->httpClient->request(
                    'POST',
                    $endpoint,
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $token,
                            'Content-Type' => 'application/json',
                        ],
                        'json' => $this->normalizeIfoodRequestPayload($payload),
                        'timeout' => 15,
                        'max_duration' => 20,
                    ]
                );

                $statusCode = $response->getStatusCode();
                $rawBody = $response->getContent(false);
                $body = $this->decodeIfoodActionResponseBody((string) $rawBody);

                self::$logger->info('iFood order action response', [
                    'order_id' => $orderId,
                    'action' => $actionPath,
                    'status_code' => $statusCode,
                    'response' => $body,
                ]);

                return [
                    'status' => $statusCode,
                    'body' => $body,
                ];
            } catch (\Throwable $e) {
                self::$logger->error('iFood order action endpoint error', [
                    'order_id' => $orderId,
                    'action' => $actionPath,
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'status' => 500,
                    'body' => [
                        'message' => $e->getMessage(),
                    ],
                ];
            }
        } catch (\Throwable $e) {
            self::$logger->error('iFood order action error', [
                'order_id' => $orderId,
                'action' => $actionPath,
                'error' => $e->getMessage(),
            ]);
            return [
                'status' => 500,
                'body' => [
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    private function callIfoodDisputeAction(string $disputeId, string $actionPath, array $payload = []): ?array
    {
        try {
            $token = $this->getAccessToken();
            if (!$token) {
                self::$logger->warning('iFood dispute action skipped because token is unavailable', [
                    'dispute_id' => $disputeId,
                    'action' => $actionPath,
                ]);
                return null;
            }

            $endpoint = self::API_BASE_URL . '/order/v1.0/disputes/' . rawurlencode($disputeId) . $actionPath;

            try {
                self::$logger->info('iFood dispute action request', [
                    'dispute_id' => $disputeId,
                    'action' => $actionPath,
                    'payload' => $payload,
                ]);

                $response = $this->httpClient->request(
                    'POST',
                    $endpoint,
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $token,
                            'Content-Type' => 'application/json',
                        ],
                        'json' => $this->normalizeIfoodRequestPayload($payload),
                        'timeout' => 15,
                        'max_duration' => 20,
                    ]
                );

                $statusCode = $response->getStatusCode();
                $body = $this->decodeIfoodActionResponseBody((string) $response->getContent(false));

                self::$logger->info('iFood dispute action response', [
                    'dispute_id' => $disputeId,
                    'action' => $actionPath,
                    'status_code' => $statusCode,
                    'response' => $body,
                ]);

                return [
                    'status' => $statusCode,
                    'body' => $body,
                ];
            } catch (\Throwable $e) {
                self::$logger->error('iFood dispute action endpoint error', [
                    'dispute_id' => $disputeId,
                    'action' => $actionPath,
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]);

                return [
                    'status' => 500,
                    'body' => [
                        'message' => $e->getMessage(),
                    ],
                ];
            }
        } catch (\Throwable $e) {
            self::$logger->error('iFood dispute action error', [
                'dispute_id' => $disputeId,
                'action' => $actionPath,
                'error' => $e->getMessage(),
            ]);
            return [
                'status' => 500,
                'body' => [
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    private function callIfoodShippingAction(string $orderId, string $actionPath, array $payload = []): ?array
    {
        try {
            $token = $this->getAccessToken();
            if (!$token) {
                self::$logger->warning('iFood shipping action skipped because token is unavailable', [
                    'order_id' => $orderId,
                    'action' => $actionPath,
                ]);
                return null;
            }

            self::$logger->info('iFood shipping action request', [
                'order_id' => $orderId,
                'action' => $actionPath,
                'payload' => $payload,
            ]);

            $response = $this->httpClient->request(
                'POST',
                self::API_BASE_URL . '/shipping/v1.0/orders/' . rawurlencode($orderId) . $actionPath,
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $this->normalizeIfoodRequestPayload($payload),
                    'timeout' => 15,
                    'max_duration' => 20,
                ]
            );

            $statusCode = $response->getStatusCode();
            $rawBody = $response->getContent(false);
            $body = $this->decodeIfoodActionResponseBody((string) $rawBody);

            self::$logger->info('iFood shipping action response', [
                'order_id' => $orderId,
                'action' => $actionPath,
                'status_code' => $statusCode,
                'response' => $body,
            ]);

            return [
                'status' => $statusCode,
                'body' => $body,
            ];
        } catch (\Throwable $e) {
            self::$logger->error('iFood shipping action error', [
                'order_id' => $orderId,
                'action' => $actionPath,
                'error' => $e->getMessage(),
            ]);
            return [
                'status' => 500,
                'body' => [
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    private function callIfoodShippingMerchantAction(
        string $merchantId,
        string $method,
        string $path,
        array $query = [],
        array $payload = []
    ): ?array {
        try {
            $token = $this->getAccessToken();
            if (!$token) {
                self::$logger->warning('iFood shipping merchant action skipped because token is unavailable', [
                    'merchant_id' => $merchantId,
                    'method' => $method,
                    'path' => $path,
                ]);

                return null;
            }

            $options = [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'query' => $query,
                'timeout' => 20,
                'max_duration' => 30,
            ];

            if ($payload !== []) {
                $options['json'] = $this->normalizeIfoodRequestPayload($payload);
            }

            self::$logger->info('iFood shipping merchant action request', [
                'merchant_id' => $merchantId,
                'method' => $method,
                'path' => $path,
                'query' => $query,
                'payload' => $payload,
            ]);

            $response = $this->httpClient->request(
                strtoupper($method),
                self::API_BASE_URL . '/shipping/v1.0/merchants/' . rawurlencode($merchantId) . $path,
                $options
            );

            $statusCode = $response->getStatusCode();
            $rawBody = $response->getContent(false);
            $body = $this->decodeIfoodActionResponseBody((string) $rawBody);

            self::$logger->info('iFood shipping merchant action response', [
                'merchant_id' => $merchantId,
                'method' => $method,
                'path' => $path,
                'status_code' => $statusCode,
                'response' => $body,
            ]);

            return [
                'status' => $statusCode,
                'body' => $body,
            ];
        } catch (\Throwable $e) {
            self::$logger->error('iFood shipping merchant action error', [
                'merchant_id' => $merchantId,
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'body' => [
                    'message' => $e->getMessage(),
                ],
            ];
        }
    }

    private function shouldFallbackActionEndpoint(?array $response): bool
    {
        if (!$response) {
            return true;
        }

        $statusCode = (int) ($response['status'] ?? 0);
        if ($statusCode >= 200 && $statusCode < 300) {
            return false;
        }

        return in_array($statusCode, [404, 405, 500, 502, 503, 504], true);
    }

    private function resolveDispatchFlowForOrder(Order $order): string
    {
        $storedState = $this->getStoredOrderIntegrationState($order);
        $orderType = strtoupper($this->normalizeString($storedState['order_type'] ?? null));
        if (in_array($orderType, ['TAKEOUT', 'DINE_IN'], true)) {
            return 'pickup';
        }

        $deliveredBy = strtoupper($this->normalizeString($storedState['delivered_by'] ?? null));
        if ($deliveredBy === 'MERCHANT') {
            return 'merchant';
        }

        if ($deliveredBy === 'IFOOD') {
            return 'ifood';
        }

        $deliveryMode = strtolower($this->normalizeString($storedState['delivery_mode'] ?? null));
        if (in_array($deliveryMode, ['merchant', 'store', 'self', 'self_delivery', 'own', 'own_fleet'], true)) {
            return 'merchant';
        }

        return 'ifood';
    }

    private function dispatchOrderByDeliveryMode(Order $order, string $orderId): ?array
    {
        $flow = $this->resolveDispatchFlowForOrder($order);

        if ($flow === 'merchant') {
            $payload = ['deliveredBy' => 'MERCHANT'];
            $shippingResponse = $this->callIfoodShippingAction($orderId, '/dispatch', $payload);
            if (!$this->shouldFallbackActionEndpoint($shippingResponse)) {
                return $shippingResponse;
            }

            $orderResponse = $this->callIfoodOrderAction($orderId, '/dispatch', $payload);
            return $orderResponse ?: $shippingResponse;
        }

        $orderResponse = $this->callIfoodOrderAction($orderId, '/dispatch');
        if (!$this->shouldFallbackActionEndpoint($orderResponse)) {
            return $orderResponse;
        }

        $shippingResponse = $this->callIfoodShippingAction($orderId, '/dispatch');
        return $shippingResponse ?: $orderResponse;
    }

    private function cancelByShop(string $orderId, ?string $cancellationCode = null): ?array
    {
        $payload = [];
        if ($cancellationCode !== null && $cancellationCode !== '') {
            $payload['cancellationCode'] = $cancellationCode;
            $payload['reason'] = $cancellationCode;
        }
        return $this->callIfoodOrderAction($orderId, '/requestCancellation', $payload);
    }

    private function resolveDefaultIfoodCancellationCode(?string $orderId = null): ?string
    {
        $rawReasons = $this->fetchIfoodCancellationReasons($orderId);
        foreach ($rawReasons as $reason) {
            if (!is_array($reason)) {
                continue;
            }

            $code = $this->normalizeString(
                $reason['cancelCodeId'] ?? $reason['cancelCode'] ?? $reason['code'] ?? null
            );
            if ($code !== '') {
                return $code;
            }
        }

        return null;
    }

    private function confirmOrder(string $orderId): ?array
    {
        $response = $this->callIfoodOrderAction($orderId, '/confirm');
        if (!$this->shouldFallbackActionEndpoint($response)) {
            return $response;
        }

        $fallbackResponse = $this->callIfoodOrderAction($orderId, '/accept');
        return $fallbackResponse ?: $response;
    }

    private function extractCancellationReasonListFromResponse(array $data): array
    {
        if (array_is_list($data)) {
            $hasReasonShape = false;
            foreach ($data as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $code = $this->normalizeString(
                    $entry['cancelCodeId'] ?? $entry['cancelCode'] ?? $entry['code'] ?? null
                );
                $description = $this->normalizeString(
                    $entry['description'] ?? $entry['reason'] ?? $entry['title'] ?? $entry['name'] ?? null
                );
                if ($code !== '' || $description !== '') {
                    $hasReasonShape = true;
                    break;
                }
            }

            if ($hasReasonShape) {
                return $data;
            }
        }

        if (is_array($data['cancellationReasons'] ?? null)) {
            return $data['cancellationReasons'];
        }

        if (is_array($data['reasons'] ?? null)) {
            return $data['reasons'];
        }

        if (is_array($data['data'] ?? null)) {
            if (is_array($data['data']['cancellationReasons'] ?? null)) {
                return $data['data']['cancellationReasons'];
            }

            if (is_array($data['data']['reasons'] ?? null)) {
                return $data['data']['reasons'];
            }
        }

        return [];
    }

    private function fetchIfoodCancellationReasons(?string $orderId = null): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return [];
        }

        $endpoints = [];
        $normalizedOrderId = $this->normalizeString($orderId);
        if ($normalizedOrderId !== '') {
            $encodedOrderId = rawurlencode($normalizedOrderId);
            $endpoints[] = self::API_BASE_URL . '/order/v1.0/orders/' . $encodedOrderId . '/cancellationReasons';
        }
        $endpoints[] = self::API_BASE_URL . '/order/v1.0/cancellation/reasons';

        try {
            foreach ($endpoints as $endpoint) {
                $response = $this->httpClient->request('GET', $endpoint, [
                    'headers' => ['Authorization' => 'Bearer ' . $token],
                    'timeout' => 15,
                    'max_duration' => 20,
                ]);
                if ($response->getStatusCode() !== 200) {
                    continue;
                }

                $data = $response->toArray(false);
                $reasons = $this->extractCancellationReasonListFromResponse($data);
                if ($reasons !== []) {
                    return $reasons;
                }
            }

            return [];
        } catch (\Throwable $e) {
            self::$logger->error('iFood cancellation reasons fetch failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getIfoodCancellationReasons(?Order $order = null): array
    {
        $this->init();
        $orderId = $order ? $this->resolveRemoteOrderId($order) : null;
        $raw = $this->fetchIfoodCancellationReasons($orderId);

        $mapped = array_map(fn(array $r) => [
            'reason_id'            => $this->normalizeString(
                $r['cancelCodeId'] ?? $r['cancelCode'] ?? $r['code'] ?? null
            ),
            'description'          => $this->normalizeString(
                $r['description'] ?? $r['reason'] ?? $r['title'] ?? $r['name'] ?? null
            ),
            'applicable'           => true,
            'requires_description' => false,
        ], $raw);

        return array_values(array_filter(
            $mapped,
            static fn(array $reason): bool => $reason['reason_id'] !== ''
        ));
    }

    public function performConfirmAction(Order $order): array
    {
        $this->init();
        $orderId = $this->resolveRemoteOrderId($order);
        if (!$orderId) {
            return $this->buildUnavailableOrderActionResponse('Pedido iFood sem identificador remoto.');
        }
        return $this->persistOrderActionResult(
            $order,
            'confirm',
            $this->confirmOrder($orderId),
            'confirmed',
            ['realStatus' => 'open', 'status' => 'preparing']
        );
    }

    private function readyOrder(string $orderId): ?array
    {
        return $this->callIfoodOrderAction($orderId, '/readyToPickup');
    }

    private function verifyDeliveryCode(string $orderId, string $deliveryCode): ?array
    {
        return $this->callIfoodOrderAction($orderId, '/verifyDeliveryCode', [
            'code' => $deliveryCode,
        ]);
    }

    private function deliveredOrder(string $orderId): ?array
    {
        return $this->callIfoodOrderAction($orderId, '/dispatch');
    }

    // SINCRONIZACAO DE STATUS COM iFOOD
    // Envia para iFood o novo status do pedido (pronto, entregue, cancelado)
    public function changeStatus(Order $order)
    {
        $action = $this->extractPendingOrderAction($order);
        if (($action['remote_sync'] ?? false) !== true) {
            return null;
        }

        $payload = is_array($action['payload'] ?? null) ? $action['payload'] : [];
        $reason = $this->normalizeString($payload['reason'] ?? null);
        $cancellationCode = $this->normalizeString(
            $payload['cancellation_code'] ?? ($payload['reason_id'] ?? null)
        );

        match ($action['name'] ?? '') {
            'cancel' => $this->performCancelAction(
                $order,
                $reason !== '' ? $reason : null,
                $cancellationCode !== '' ? $cancellationCode : null
            ),
            'ready' => $this->performReadyAction($order),
            'delivered' => $this->performDeliveredAction(
                $order,
                $this->normalizeString($payload['delivery_code'] ?? null) ?: null,
                $this->normalizeString($payload['locator'] ?? null) ?: null
            ),
            'confirm' => $this->performConfirmAction($order),
            default => null,
        };

        return null;
    }

    // ATUALIZA PRECO DO ITEM NO CATALOGO IFOOD
    // PATCH /catalog/v2.0/merchants/{merchantId}/items/price
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
            $response = $this->httpClient->request(
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
            $response = $this->httpClient->request(
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
            $response = $this->httpClient->request(
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
            $response = $this->httpClient->request(
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
            $response = $this->httpClient->request(
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
            $response = $this->httpClient->request(
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
