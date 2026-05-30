<?php

namespace ControleOnline\Service\Marketplace;

use ControleOnline\Service\AddressService;
use ControleOnline\Service\Client\IfoodClient;
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
use ControleOnline\Service\iFoodService;
use ControleOnline\Service\Marketplace\AbstractMarketplaceService;
use ControleOnline\Service\Client\WebsocketClient;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationHandlerInterface;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationStateProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceLogisticsQuoteProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceOrderSnapshotProviderInterface;
use ControleOnline\Service\Marketplace\IfoodPeopleOperationsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use ControleOnline\Service\LoggerService;
use DateTime;
use ControleOnline\Event\EntityChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\Service\Attribute\Required;

class IfoodStoreOperationsService extends AbstractMarketplaceService
{
    private const APP_CONTEXT = Order::APP_IFOOD;

    protected function getMarketplaceApp(): string
    {
        return self::APP_CONTEXT;
    }

    private ?IfoodFinancialOperationsService $ifoodFinancialOperationsService = null;

    private function buildOrderIntegrationLockKey(string $orderId): string
    {
        return 'ifood:order:' . substr(sha1($orderId), 0, 40);
    }

    private function acquireOrderIntegrationLock(string $orderId): bool
    {
        try {
            $acquired = (int) $this->entityManager->getConnection()->fetchOne(
                'SELECT GET_LOCK(:lockKey, 5)',
                ['lockKey' => $this->buildOrderIntegrationLockKey($orderId)]
            );

            if ($acquired !== 1) {
                self::$logger?->warning('iFood could not acquire order integration lock in time', [
                    'order_id' => $orderId,
                    'lock_key' => $this->buildOrderIntegrationLockKey($orderId),
                ]);
            }

            return $acquired === 1;
        } catch (\Throwable $e) {
            self::$logger?->warning('iFood could not acquire order integration lock', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    #[Required]
    public function setIfoodFinancialOperationsService(IfoodFinancialOperationsService $ifoodFinancialOperationsService): void
    {
        $this->ifoodFinancialOperationsService = $ifoodFinancialOperationsService;
    }

    private function releaseOrderIntegrationLock(string $orderId): void
    {
        try {
            $this->entityManager->getConnection()->executeQuery(
                'SELECT RELEASE_LOCK(:lockKey)',
                ['lockKey' => $this->buildOrderIntegrationLockKey($orderId)]
            );
        } catch (\Throwable $e) {
            self::$logger?->warning('iFood could not release order integration lock', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolveIfoodPeopleOperationsService(): ?IfoodPeopleOperationsService
    {
        if (!$this->container instanceof ContainerInterface || !$this->container->has(IfoodPeopleOperationsService::class)) {
            return null;
        }

        $service = $this->container->get(IfoodPeopleOperationsService::class);

        return $service instanceof IfoodPeopleOperationsService ? $service : null;
    }

    private function resolveIfoodOrderOperationsService(): ?IfoodOrderOperationsService
    {
        $service = $this->resolveMarketplaceServiceInstance(IfoodOrderOperationsService::class);
        if (!$service instanceof IfoodOrderOperationsService) {
            return null;
        }

        $iFoodService = $this->resolveMarketplaceServiceInstance(iFoodService::class);
        if ($iFoodService instanceof iFoodService && method_exists($service, 'setIfoodService')) {
            $service->setIfoodService($iFoodService);
        }

        return $service;
    }

    private function resolveIfoodService(): ?iFoodService
    {
        $service = $this->resolveMarketplaceServiceInstance(iFoodService::class);

        return $service instanceof iFoodService ? $service : null;
    }

    private function findOrderByExternalId(string $orderId): ?Order
    {
        if ($orderId === '') {
            return null;
        }

        $order = $this->extraDataService->getEntityByExtraData(
            self::APP_CONTEXT,
            'code',
            $orderId,
            Order::class
        );
        if ($order instanceof Order) {
            return $order;
        }

        $order = $this->extraDataService->getEntityByExtraData(
            self::APP_CONTEXT,
            'id',
            $orderId,
            Order::class
        );
        if ($order instanceof Order) {
            return $order;
        }

        return null;
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

    private function extractEventTimestamp(array $payload): string
    {
        $raw = $payload['createdAt']
            ?? ($payload['created_at'] ?? ($payload['__webhook']['event_at'] ?? null));

        return $this->normalizeMarketplaceDateTime($raw)->format('Y-m-d H:i:s');
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

    public function extractOrderRemarkFromPayload(array $orderPayload): string
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

    private function resolveOrderDetailsFromEvent(string $orderId, array $event, ?Order $order = null): array
    {
        $eventOrderDetails = is_array($event['order'] ?? null) ? $event['order'] : [];
        $storedOrderDetails = [];
        $otherInformations = [];

        if ($order instanceof Order) {
            $otherInformations = $this->getDecodedOrderOtherInformations($order);
            $context = $this->decodeOrderOtherInformationsValue($otherInformations[self::APP_CONTEXT] ?? null);
            if (is_array($context['order'] ?? null) && $context['order'] !== []) {
                $storedOrderDetails = $context['order'];
            }
        }

        if ($eventOrderDetails !== []) {
            $orderDetails = $storedOrderDetails !== []
                ? array_replace_recursive($storedOrderDetails, $eventOrderDetails)
                : $eventOrderDetails;
        } elseif ($storedOrderDetails !== []) {
            $orderDetails = $storedOrderDetails;
        } else {
            $fetchedOrderDetails = $this->fetchOrderDetails($orderId);
            $orderDetails = is_array($fetchedOrderDetails) ? $fetchedOrderDetails : [];
        }

        if ($order instanceof Order && $orderDetails !== []) {
            $this->persistResolvedIfoodOrderDetails($order, $event, $orderDetails, $otherInformations);
        }

        return is_array($orderDetails) ? $orderDetails : [];
    }

    private function persistResolvedIfoodOrderDetails(Order $order, array $event, array $orderDetails, array $otherInformations = []): void
    {
        if ($otherInformations === []) {
            $otherInformations = $this->getDecodedOrderOtherInformations($order);
        }

        $context = $this->decodeOrderOtherInformationsValue($otherInformations[self::APP_CONTEXT] ?? null);
        if ($context === []) {
            $context = [];
        }

        $eventKey = $this->resolveEventCode($event);
        if ($eventKey === '') {
            $eventKey = 'PLACED';
        }

        if (!is_array($context[$eventKey] ?? null)) {
            $context[$eventKey] = [];
        }

        $context[$eventKey]['order'] = $orderDetails;
        $context[$eventKey]['order_details_cached_at'] = date('Y-m-d H:i:s');
        $context['latest_event_type'] = $eventKey;

        $otherInformations[self::APP_CONTEXT] = $context;
        $order->setOtherInformations($otherInformations);
        $this->entityManager->persist($order);
    }

    private function getIfoodExtraDataValue(string $entityName, int $entityId, string $fieldName = 'code'): ?string
    {
        return $this->extraDataService->getExtraDataValue(
            self::APP_CONTEXT,
            $entityName,
            $entityId,
            $fieldName
        );
    }

    private function upsertIfoodExtraDataValue(string $entityName, int $entityId, string $fieldName, mixed $value): void
    {
        $this->extraDataService->upsertExtraDataValue(
            self::APP_CONTEXT,
            $entityName,
            $entityId,
            $fieldName,
            $value,
            'text',
            self::APP_CONTEXT
        );
    }

    private function decodeOrderOtherInformationsValue(mixed $value): array
    {
        $decoded = $this->decodeEntityOtherInformationsValue($value);

        return is_array($decoded) ? $decoded : [];
    }

    private function getDecodedOrderOtherInformations(Order $order): array
    {
        $decoded = $this->getDecodedEntityOtherInformations($order);

        return is_array($decoded) ? $decoded : [];
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

    private function persistProviderIntegrationState(People $provider, array $fields): void
    {
        $legacyFields = [];
        $stateFields = [];

        foreach ($fields as $fieldName => $value) {
            $normalizedFieldName = trim((string) $fieldName);
            if ($normalizedFieldName === '') {
                continue;
            }

            if (in_array($normalizedFieldName, ['code', 'merchant_id'], true)) {
                $legacyFields[$normalizedFieldName] = $value;
                continue;
            }

            $stateFields[$normalizedFieldName] = $value;
        }

        if ($stateFields !== []) {
            $this->mergeEntityOtherInformations($provider, self::APP_CONTEXT, $stateFields);
        }

        foreach ($legacyFields as $fieldName => $value) {
            $this->upsertIfoodExtraDataValue('People', (int) $provider->getId(), $fieldName, $value);
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
            $response = $this->ifoodClient->requestMerchantEndpoint('GET', '/merchants');

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
            $response = $this->ifoodClient->requestMerchantEndpoint('GET', '/merchants/' . rawurlencode($merchantId));
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
    public function getStoredIntegrationState(People $provider, bool $includeAuthCheck = false): array
    {
        $this->init();

        $providerId = (int) $provider->getId();
        $otherInformations = $this->getDecodedEntityOtherInformations($provider);
        $context = $this->decodeEntityOtherInformationsValue($otherInformations[self::APP_CONTEXT] ?? null);
        $merchantId = $this->normalizeString(
            $context['code']
                ?? $context['merchant_id']
                ?? $this->getIfoodExtraDataValue('People', $providerId, 'code')
                ?? $this->getIfoodExtraDataValue('People', $providerId, 'merchant_id')
                ?? null
        );
        $merchantStatus = strtoupper($this->normalizeString(
            $context['merchant_status']
                ?? $this->getIfoodExtraDataValue('People', $providerId, 'merchant_status')
        ));
        $remoteConnectedRaw = $this->normalizeString(
            $context['remote_connected']
                ?? $this->getIfoodExtraDataValue('People', $providerId, 'remote_connected')
        );

        $connected = $merchantId !== '';
        $remoteConnected = $remoteConnectedRaw !== '' ? $remoteConnectedRaw === '1' : $connected;

        return [
            'connected' => $connected,
            'remote_connected' => $remoteConnected,
            'ifood_code' => $merchantId !== '' ? $merchantId : null,
            'merchant_id' => $merchantId !== '' ? $merchantId : null,
            'merchant_name' => $context['merchant_name'] ?? $this->getIfoodExtraDataValue('People', $providerId, 'merchant_name'),
            'merchant_status' => $merchantStatus !== '' ? $merchantStatus : null,
            'merchant_status_label' => $this->normalizeMerchantStatusLabel($merchantStatus),
            'online' => in_array($merchantStatus, ['AVAILABLE', 'ONLINE', 'OPEN'], true),
            'connected_at' => $context['connected_at'] ?? $this->getIfoodExtraDataValue('People', $providerId, 'connected_at'),
            'disconnected_at' => $context['disconnected_at'] ?? $this->getIfoodExtraDataValue('People', $providerId, 'disconnected_at'),
            'last_sync_at' => $context['last_sync_at'] ?? $this->getIfoodExtraDataValue('People', $providerId, 'last_sync_at'),
            'last_error_code' => $context['last_error_code'] ?? $this->getIfoodExtraDataValue('People', $providerId, 'last_error_code'),
            'last_error_message' => $context['last_error_message'] ?? $this->getIfoodExtraDataValue('People', $providerId, 'last_error_message'),
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
            $response = $this->ifoodClient->requestMerchantEndpoint('GET', '/merchants/' . rawurlencode($merchantId) . '/status');
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
            $interruptions = $this->listInterruptionsRaw($merchantId);

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

    public function isStoreStatusWebhookEvent(array $event, string $eventCode): bool
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

    public function resolveWebhookMerchantStatus(array $event): ?string
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

        $provider = $this->extraDataService->getEntityByExtraData(
            self::APP_CONTEXT,
            'code',
            $merchantId,
            People::class
        );
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
            $response = $this->ifoodClient->requestMerchantEndpoint('POST', '/merchants/' . rawurlencode($merchantId) . '/interruptions', [
                'json' => $interruption,
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

        $interruptions = $this->listInterruptionsRaw($merchantId);
        if (empty($interruptions)) {
            return ['errno' => 0, 'errmsg' => 'ok', 'data' => ['removed' => 0, 'online' => true]];
        }

        $removed = 0;
        $lastError = null;
        foreach ($interruptions as $interruption) {
            $id = $this->normalizeString($interruption['id'] ?? null);
            if ($id === '') continue;
            try {
                $resp = $this->ifoodClient->requestMerchantEndpoint('DELETE', '/merchants/' . rawurlencode($merchantId) . '/interruptions/' . rawurlencode($id));
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
            $response = $this->ifoodClient->requestMerchantEndpoint('DELETE', '/merchants/' . rawurlencode($merchantId) . '/interruptions/' . rawurlencode($interruptionId));
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

    private function listInterruptionsRaw(string $merchantId): array
    {
        try {
            $response = $this->ifoodClient->requestMerchantEndpoint('GET', '/merchants/' . rawurlencode($merchantId) . '/interruptions');
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

        $provider = $this->extraDataService->getEntityByExtraData(
            self::APP_CONTEXT,
            'code',
            $merchantId,
            People::class
        );

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

            $peopleOperationsService = $this->resolveIfoodPeopleOperationsService();
            $client = $peopleOperationsService instanceof IfoodPeopleOperationsService
                ? $peopleOperationsService->discoveryClient($provider, is_array($orderDetails['customer'] ?? null) ? $orderDetails['customer'] : [])
                : null;
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
            $this->applyMarketplaceOrderDate($order, $this->extractEventTimestamp($json));
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
            $ifoodService = $this->resolveIfoodService();
            $extendedState = array_merge(
                $extendedState,
                $ifoodService instanceof iFoodService ? $ifoodService->extractOrderDetailSnapshot($orderDetails) : []
            );
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
        $financialOperationsService = $this->ifoodFinancialOperationsService;
        if (!$financialOperationsService instanceof IfoodFinancialOperationsService) {
            throw new \LogicException('IfoodFinancialOperationsService indisponivel.');
        }

        foreach ($payments as $payment) {
            if (!is_array($payment)) {
                continue;
            }

            $amount = round((float) ($payment['value'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            $paymentTypeData = $financialOperationsService->resolveIfoodInvoicePaymentTypeData($payment);
            $isPrepaid = (bool) ($payment['prepaid'] ?? false);
            $paymentType = $financialOperationsService->resolveIfoodProviderPaymentType(
                $order->getProvider(),
                $paymentTypeData
            );
            $receivableWallet = $financialOperationsService->resolveIfoodReceivableWallet(
                $order,
                $paymentType,
                $paymentTypeData,
                $isPrepaid
            );
            $financialOperationsService->ensureIfoodWalletPaymentType(
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

            $financialOperationsService->applyIfoodInvoiceContract(
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
            $peopleOperationsService = $this->resolveIfoodPeopleOperationsService();
            $client = $peopleOperationsService instanceof IfoodPeopleOperationsService
                ? $peopleOperationsService->discoveryClient(
                    $provider,
                    is_array($orderDetails['customer'] ?? null) ? $orderDetails['customer'] : []
                )
                : null;

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

    public function validateIfoodQuoteRoute(?Address $pickupAddress, ?Address $dropoffAddress): ?string
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

    public function buildIfoodShippingAddressPayload(Address $address): array
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

    public function persistIfoodQuoteState(Order $order, array $storedState, array $logisticsState): void
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

    public function getStoredIfoodQuoteState(Order $order): array
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
    private function addDelivery(Order $order, array $orderDetails)
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
        $financialOperationsService = $this->ifoodFinancialOperationsService;
        if (!$financialOperationsService instanceof IfoodFinancialOperationsService) {
            throw new \LogicException('IfoodFinancialOperationsService indisponivel.');
        }

        $paymentType = $financialOperationsService->resolveIfoodSettlementPaymentType(
            $order->getProvider(),
            $providerWallet
        );
        $order->setRetrieveContact(self::$foodPeople);

        $financialOperationsService->createIfoodPayableInvoice(
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
        $financialOperationsService = $this->ifoodFinancialOperationsService;
        if (!$financialOperationsService instanceof IfoodFinancialOperationsService) {
            throw new \LogicException('IfoodFinancialOperationsService indisponivel.');
        }

        $paymentType = $financialOperationsService->resolveIfoodSettlementPaymentType(
            $order->getProvider(),
            $providerWallet
        );
        $financialOperationsService->createIfoodPayableInvoice(
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
        return $this->ifoodClient->getAccessToken();
    }

    // FETCH DETALHES DO PEDIDO
    // Chama API do iFood para buscar informa��es completas do pedido (cliente, produtos, entrega, pagamentos)
    public function fetchOrderDetails(string $orderId): ?array
    {
        try {
            $encodedOrderId = rawurlencode($orderId);
            $endpoint = '/order/v1.0/orders/' . $encodedOrderId;
            $token = $this->getAccessToken();
            if (!$token) {
                self::$logger->warning('iFood order details request skipped because token is unavailable', [
                    'order_id' => $orderId,
                    'endpoint' => $endpoint,
                ]);

                return null;
            }

            try {
                $response = $this->ifoodClient->requestOrderEndpoint('GET', '/orders/' . $encodedOrderId);

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
                        $this->ifoodClient->resetAccessTokenCache();
                    }

                    return null;
                }

                if ($rawBody === '') {
                    return [];
                }

                $data = json_decode((string) $rawBody, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                    return $data;
                }

                return ['message' => (string) $rawBody];
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
    // DESCOBERTA/CRIAÇÃO DO PRODUTO
    // Busca produto existente por múltiplas chaves (iFood ID, código externo, EAN, nome)
    // Se não encontrar, cria novo produto. Se tem pai, associa como grupo/componente
    private function discoveryProduct(Order $order, array $item, ?Product $parentProduct = null, string $productType = 'product'): Product
    {
        $codProductiFood = $item['id'];
        $product = $this->extraDataService->getEntityByExtraData(
            self::APP_CONTEXT,
            'code',
            $codProductiFood,
            Product::class
        );

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

    public function isReadyQueueTransition(OrderProductQueue $oldQueue, OrderProductQueue $newQueue): bool
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

    public function performReadyAction(Order $order): array
    {
        $service = $this->resolveIfoodOrderOperationsService();
        $result = $service instanceof IfoodOrderOperationsService
            ? $service->performReadyAction($order)
            : null;

        return is_array($result)
            ? $result
            : ['errno' => 1, 'errmsg' => 'A acao ready do iFood nao esta disponivel.'];
    }

    public function changeStatus(Order $order)
    {
        $service = $this->resolveIfoodOrderOperationsService();
        if ($service instanceof IfoodOrderOperationsService) {
            return $service->changeStatus($order);
        }

        return null;
    }

}
