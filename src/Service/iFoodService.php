<?php

namespace ControleOnline\Service;

use ControleOnline\Service\AddressService;
use ControleOnline\Entity\Integration;
use ControleOnline\Entity\Order;
use ControleOnline\Entity\OrderProduct;
use ControleOnline\Entity\People;
use ControleOnline\Entity\Product;
use ControleOnline\Entity\ProductGroup;
use ControleOnline\Entity\ProductGroupProduct;
use ControleOnline\Entity\ProductUnity;
use ControleOnline\Entity\User;
use ControleOnline\Service\Client\WebsocketClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use ControleOnline\Service\LoggerService;
use DateTime;
use Exception;
use ControleOnline\Event\EntityChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class iFoodService extends DefaultFoodService implements EventSubscriberInterface
{
    private const APP_CONTEXT = 'iFood';
    private const API_BASE_URL = 'https://merchant-api.ifood.com.br';
    private static array $authTokenCache = [];
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

        $orderId = $this->normalizeString($event['orderId'] ?? null);
        if ($orderId === '') {
            self::$logger->warning('iFood event ignored because orderId is missing', [
                'integration_id' => $integration->getId(),
                'event_code' => $eventCode,
            ]);
            return null;
        }

        $order = $this->findOrderByExternalId($orderId);
        if (!$order instanceof Order) {
            $order = $this->addOrder($event);
        }

        if (!$order instanceof Order) {
            self::$logger->warning('iFood event ignored because local order could not be resolved', [
                'integration_id' => $integration->getId(),
                'event_code' => $eventCode,
                'order_id' => $orderId,
            ]);
            return null;
        }

        $this->appendOrderEventPayload($order, $eventCode, $event);
        $this->persistIncomingEventState($order, $integration, $event);

        if ($eventCode === 'CANCELLED') {
            $this->applyLocalCanceledStatus($order);
        }

        if ($eventCode === 'CONCLUDED') {
            $this->applyLocalClosedStatus($order);
        }

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }

    private function resolveIncomingEvent(Integration $integration): ?array
    {
        $payload = json_decode((string) $integration->getBody(), true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            self::$logger->warning('iFood payload ignored because JSON is invalid', [
                'integration_id' => $integration->getId(),
                'json_error' => json_last_error_msg(),
            ]);
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
            ?? ($event['__webhook']['event_type'] ?? null);

        return strtoupper($this->normalizeString($code));
    }

    private function findOrderByExternalId(string $orderId): ?Order
    {
        if ($orderId === '') {
            return null;
        }

        $order = $this->extraDataService->getEntityByExtraData(self::$app, 'code', $orderId, Order::class);
        if ($order instanceof Order) {
            return $order;
        }

        $order = $this->extraDataService->getEntityByExtraData(self::$app, 'id', $orderId, Order::class);
        if ($order instanceof Order) {
            return $order;
        }

        return null;
    }

    private function appendOrderEventPayload(Order $order, string $eventCode, array $payload): void
    {
        $entryKey = $eventCode !== '' ? $eventCode : 'UNKNOWN';

        $otherInformations = (array) $order->getOtherInformations(true);
        $otherInformations[$entryKey] = $payload;
        $otherInformations['latest_event_type'] = $entryKey;

        $order->addOtherInformations(self::$app, $otherInformations);
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
        return match ($eventCode) {
            'PLACED' => 'new',
            'CONFIRMED' => 'confirmed',
            'STARTED' => 'preparing',
            'READY' => 'ready',
            'DISPATCHED' => 'dispatching',
            'CONCLUDED' => 'concluded',
            'CANCELLED' => 'cancelled',
            'CANCELLATION_REQUESTED' => 'cancellation_requested',
            default => strtolower($eventCode),
        };
    }

    private function persistIncomingEventState(Order $order, Integration $integration, array $payload): void
    {
        $eventCode = $this->resolveEventCode($payload);
        $meta = $this->extractWebhookMeta($payload);
        $eventTimestamp = $this->extractEventTimestamp($payload);
        $orderId = $this->normalizeString($payload['orderId'] ?? ($meta['order_id'] ?? null));
        $merchantId = $this->normalizeString($payload['merchantId'] ?? ($meta['shop_id'] ?? null));

        $this->persistOrderIntegrationState($order, [
            'id' => $orderId,
            'code' => $orderId,
            'merchant_id' => $merchantId,
            'last_event_type' => $eventCode,
            'last_event_at' => $eventTimestamp,
            'remote_order_state' => $this->resolveRemoteOrderStateByEventCode($eventCode),
            'webhook_event_id' => $meta['event_id'],
            'webhook_event_type' => $meta['event_type'],
            'webhook_event_at' => $meta['event_at'],
            'webhook_received_at' => $meta['received_at'],
            'webhook_processed_at' => date('Y-m-d H:i:s'),
            'last_integration_id' => (string) $integration->getId(),
        ]);
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
            return $this->decodeOrderOtherInformationsValue($order->getOtherInformations(true));
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

        $otherInformations = $this->getDecodedOrderOtherInformations($order);
        $latestEventType = $this->normalizeString($otherInformations['latest_event_type'] ?? null);
        if ($latestEventType !== '' && is_array($otherInformations[$latestEventType] ?? null)) {
            $payload = $otherInformations[$latestEventType];
            $state['last_event_type'] = $state['last_event_type'] ?: $latestEventType;
            $state['last_event_at'] = $state['last_event_at'] ?: $this->extractEventTimestamp($payload);
            $state['ifood_id'] = $state['ifood_id'] ?: $this->normalizeString($payload['orderId'] ?? null);
            $state['ifood_code'] = $state['ifood_code'] ?: $this->normalizeString($payload['orderId'] ?? null);
            $state['merchant_id'] = $state['merchant_id'] ?: $this->normalizeString($payload['merchantId'] ?? null);
            $state['remote_order_state'] = $state['remote_order_state'] ?: $this->resolveRemoteOrderStateByEventCode($latestEventType);
        }

        return $state;
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
            foreach ($this->fetchIfoodCatalogItemsV2($merchantId) as $item) {
                $ec = $this->normalizeString($item['externalCode'] ?? null);
                if ($ec !== '') {
                    $remoteByEc[$ec] = $this->normalizeString($item['id'] ?? '');
                }
            }
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
        $categoryId   = isset($row['category_id']) && $row['category_id'] !== null ? (int) $row['category_id'] : null;
        $categoryName = $categoryId !== null ? trim((string) ($row['category_name'] ?? '')) : null;

        $blockers = [];
        if ($name === '')    $blockers[] = 'Produto sem nome';
        if (!$categoryId)    $blockers[] = 'Produto sem categoria';
        if ($price <= 0)     $blockers[] = 'Produto com preco invalido';

        $ec = (string) $productId;

        return [
            'id'               => $productId,
            'name'             => $name,
            'description'      => trim((string) ($row['description'] ?? '')),
            'price'            => $price,
            'type'             => (string) ($row['type'] ?? ''),
            'category'         => $categoryId !== null ? ['id' => $categoryId, 'name' => $categoryName] : null,
            'eligible'         => empty($blockers),
            'blockers'         => $blockers,
            'published_remotely' => isset($remoteByEc[$ec]),
            'ifood_item_id'    => $remoteByEc[$ec] ?? null,
        ];
    }

    public function publishMenu(People $provider, array $productIds = []): array
    {
        $this->init();

        $state      = $this->getStoredIntegrationState($provider);
        $merchantId = $this->normalizeString($state['merchant_id'] ?? null);
        if ($merchantId === '') {
            return ['errno' => 10002, 'errmsg' => 'Loja iFood nao conectada. Vincule o merchant_id antes de publicar o cardapio.'];
        }

        /* resolve catalogId e categoryId */
        $catalogId  = $this->fetchIfoodDefaultCatalogId($merchantId);
        if ($catalogId === null) {
            return ['errno' => 10003, 'errmsg' => 'Nao foi possivel obter o catalogo iFood da loja.'];
        }
        $categoryId = $this->getOrCreateDefaultCatalogCategory($merchantId, $catalogId);
        if ($categoryId === null) {
            return ['errno' => 10003, 'errmsg' => 'Nao foi possivel obter ou criar a categoria padrao no iFood.'];
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

        /* mapa de itens existentes por externalCode */
        $remoteItems = $this->fetchIfoodCatalogItemsV2($merchantId);
        $byEc        = [];
        foreach ($remoteItems as $item) {
            $ec = $this->normalizeString($item['externalCode'] ?? null);
            if ($ec !== '') {
                $byEc[$ec] = [
                    'item_id'    => $this->normalizeString($item['id'] ?? ''),
                    'product_id' => $this->normalizeString($item['productId'] ?? ''),
                    'category_id' => $this->normalizeString($item['_categoryId'] ?? $categoryId),
                ];
            }
        }

        $pushed = 0;
        $errors = [];
        foreach ($allProducts as $prod) {
            $ec       = (string) $prod['id'];
            $existing = $byEc[$ec] ?? null;

            $ok = $this->upsertIfoodCatalogItemV2($merchantId, $prod, $existing, $categoryId);
            if ($ok) {
                $pushed++;
            } else {
                $errors[] = $prod['id'];
                self::$logger->warning('iFood catalog v2 upsert failed', ['product_id' => $prod['id']]);
            }
        }

        /* atualiza published_remotely nos produtos locais */
        if ($pushed > 0) {
            $this->syncCatalogFromIfood($provider);
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

        $synced = 0;
        foreach ($items as $item) {
            $itemId       = $this->normalizeString($item['id'] ?? null);
            $externalCode = $this->normalizeString($item['externalCode'] ?? null);
            $itemName     = $this->normalizeString($item['name'] ?? null);
            if ($itemId === '') continue;

            $product = null;
            if ($externalCode !== '' && ctype_digit($externalCode)) {
                $product = $this->entityManager->getRepository(Product::class)->findOneBy([
                    'company' => $provider,
                    'id'      => (int) $externalCode,
                ]);
            }
            if (!$product && $itemName !== '') {
                $product = $this->entityManager->getRepository(Product::class)->findOneBy([
                    'company' => $provider,
                    'product' => $itemName,
                ]);
            }

            if ($product) {
                $this->discoveryFoodCode($product, $itemId);
                $synced++;
            }
        }

        if ($synced > 0) {
            $this->entityManager->flush();
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
                c.id   AS category_id,
                c.name AS category_name
            FROM product p
            LEFT JOIN product_category pc ON pc.id = (
                SELECT MIN(pc2.id)
                FROM product_category pc2
                INNER JOIN category c2 ON c2.id = pc2.category_id
                WHERE pc2.product_id = p.id
                  AND c2.context = 'products'
            )
            LEFT JOIN category c ON c.id = pc.category_id
            WHERE p.company_id = :providerId
              AND p.active = 1
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

        return $connection->fetchAllAssociative($sql, $params);
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

    private function getOrCreateDefaultCatalogCategory(string $merchantId, string $catalogId): ?string
    {
        $token = $this->getAccessToken();
        if (!$token) return null;
        try {
            $response = $this->httpClient->request('GET',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/catalogs/' . rawurlencode($catalogId) . '/categories',
                ['headers' => ['Authorization' => 'Bearer ' . $token]]
            );
            if ($response->getStatusCode() === 200) {
                $cats = $response->toArray(false);
                if (!empty($cats) && isset($cats[0]['id'])) {
                    return $cats[0]['id'];
                }
            }
        } catch (\Throwable $e) {}

        /* cria categoria padrão */
        try {
            $response = $this->httpClient->request('POST',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/catalogs/' . rawurlencode($catalogId) . '/categories',
                [
                    'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                    'json'    => ['name' => 'Produtos', 'status' => 'AVAILABLE', 'template' => 'DEFAULT', 'sequence' => 0],
                ]
            );
            $data = $response->toArray(false);
            $id   = $this->normalizeString($data['id'] ?? null);
            return $id !== '' ? $id : null;
        } catch (\Throwable $e) {
            self::$logger->error('iFood create default category failed', ['error' => $e->getMessage()]);
            return null;
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

    private function upsertIfoodCatalogItemV2(string $merchantId, array $product, ?array $existing, string $categoryId): bool
    {
        $token = $this->getAccessToken();
        if (!$token) return false;

        $ec                = (string) $product['id'];
        $existingItemId    = $this->normalizeString($existing['item_id']    ?? null);
        $existingProductId = $this->normalizeString($existing['product_id'] ?? null);
        $usedCategoryId    = ($this->normalizeString($existing['category_id'] ?? null) !== '')
            ? $existing['category_id']
            : $categoryId;

        /* Para itens novos geramos UUIDs para ligar item ↔ produto */
        $productUuid = $existingProductId !== '' ? $existingProductId : $this->generateUuidV4();
        $itemUuid    = $existingItemId    !== '' ? $existingItemId    : $this->generateUuidV4();

        $itemBody = [
            'id'           => $itemUuid,
            'type'         => 'DEFAULT',
            'categoryId'   => $usedCategoryId,
            'status'       => 'AVAILABLE',
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

        $payload = [
            'item'         => $itemBody,
            'products'     => [$productBody],
            'optionGroups' => [],
            'options'      => [],
        ];

        try {
            $response = $this->httpClient->request('PUT',
                self::CATALOG_V2_BASE . rawurlencode($merchantId) . '/items',
                [
                    'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                    'json'    => $payload,
                ]
            );
            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return true;
            }
            self::$logger->warning('iFood catalog v2 upsert non-2xx', [
                'status'     => $status,
                'product_id' => $product['id'],
                'body'       => substr($response->getContent(false), 0, 500),
            ]);
            return false;
        } catch (\Throwable $e) {
            self::$logger->error('iFood catalog v2 upsert exception', ['product_id' => $product['id'], 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function getStoredIntegrationState(People $provider, bool $includeAuthCheck = false): array
    {
        $this->init();

        $providerId = (int) $provider->getId();
        $merchantId = $this->normalizeString(
            $this->getIfoodExtraDataValue('People', $providerId, 'merchant_id')
                ?? $this->getIfoodExtraDataValue('People', $providerId, 'code')
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
            'merchant_id' => $normalizedMerchantId,
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

    /* Fecha a loja criando uma interrupção de até 7 dias (máximo permitido pela API).
     * POST /merchant/v1.0/merchants/{id}/interruptions
     */
    public function closeStore(People $provider): array
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

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $end = $now->modify('+7 days');

        try {
            $response   = $this->httpClient->request('POST',
                self::API_BASE_URL . '/merchant/v1.0/merchants/' . rawurlencode($merchantId) . '/interruptions',
                [
                    'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
                    'json'    => [
                        'description' => 'Loja fechada pelo gestor',
                        'start'       => $now->format(\DateTimeInterface::ATOM),
                        'end'         => $end->format(\DateTimeInterface::ATOM),
                    ],
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

    private function listInterruptionsRaw(string $merchantId, string $token): array
    {
        try {
            $response = $this->httpClient->request('GET',
                self::API_BASE_URL . '/merchant/v1.0/merchants/' . rawurlencode($merchantId) . '/interruptions',
                ['headers' => ['Authorization' => 'Bearer ' . $token]]);
            if ($response->getStatusCode() !== 200) return [];
            $decoded = json_decode($response->getContent(false), true);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable $e) {
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
            $this->persistProviderIntegrationState($provider, [
                'merchant_name' => $this->normalizeString($matchedStore['name'] ?? null),
                'merchant_status' => strtoupper($this->normalizeString($matchedStore['status'] ?? null)),
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

        $provider = $this->extraDataService->getEntityByExtraData(self::$app, 'code', $merchantId, People::class);
        if (!$provider instanceof People && ctype_digit($merchantId)) {
            $provider = $this->entityManager->getRepository(People::class)->find((int) $merchantId);
        }

        if (!$provider instanceof People) {
            self::$logger->warning('iFood order ignored because provider mapping was not found', [
                'order_id' => $orderId,
                'merchant_id' => $merchantId,
            ]);
            return null;
        }

        $order = $this->findOrderByExternalId($orderId);
        if ($order instanceof Order) {
            return $order;
        }

        $orderDetails = $this->fetchOrderDetails($orderId);
        if (!$orderDetails) {
            self::$logger->error('iFood order details could not be fetched', [
                'order_id' => $orderId,
            ]);
            return null;
        }

        $json['order'] = $orderDetails;
        $paymentMethods = is_array($orderDetails['payments']['methods'] ?? null) ? $orderDetails['payments']['methods'] : [];
        $allPrepaid = !empty($paymentMethods) && array_reduce(
            $paymentMethods,
            fn(bool $carry, array $m) => $carry && ($m['prepaid'] ?? false),
            true
        );
        $status = $allPrepaid
            ? $this->statusService->discoveryStatus('open', 'paid', 'order')
            : $this->statusService->discoveryStatus('open', 'waiting payment', 'order');
        if (!$status) {
            self::$logger->error('iFood order status could not be resolved', ['all_prepaid' => $allPrepaid]);
            return null;
        }

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

        $this->addProducts($order, is_array($orderDetails['items'] ?? null) ? $orderDetails['items'] : []);
        if (is_array($orderDetails['delivery'] ?? null)) {
            $this->addDelivery($order, $orderDetails);
        }
        if (is_array($orderDetails['payments']['methods'] ?? null) && is_array($orderDetails['total'] ?? null)) {
            $this->addPayments($order, $orderDetails);
        }

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $pickupCode    = $this->normalizeString($orderDetails['pickup']['code'] ?? null);
        $virtualPhone  = $this->normalizeString($orderDetails['customer']['phone']['localizer'] ?? null);
        $extendedState = ['id' => $orderId, 'code' => $orderId, 'merchant_id' => $merchantId,
            'last_event_type' => $snapshotKey, 'last_event_at' => $this->extractEventTimestamp($json),
            'remote_order_state' => $this->resolveRemoteOrderStateByEventCode($snapshotKey),
        ];
        if ($pickupCode !== '') {
            $extendedState['pickup_code'] = $pickupCode;
        }
        if ($virtualPhone !== '') {
            $extendedState['virtual_phone'] = $virtualPhone;
        }
        $this->persistOrderIntegrationState($order, $extendedState);
        $this->entityManager->flush();

        self::$logger->info('iFood order integrated successfully', [
            'order_id' => $orderId,
            'merchant_id' => $merchantId,
            'local_order_id' => $order->getId(),
        ]);

        $this->discoveryFoodCode($order, $orderId, 'id');
        $this->discoveryFoodCode($order, $orderId, 'code');
        $this->printOrder($order);
        $this->autoConfirmOrder($order, $orderId);
        return $order;
    }

    private function autoConfirmOrder(Order $order, string $orderId): void
    {
        try {
            $raw = $this->confirmOrder($orderId);
            if ($raw) {
                $this->persistOrderActionResult($order, 'confirm', $raw, 'confirmed');
                $this->entityManager->flush();
                self::$logger->info('iFood order auto-confirmed on entry', [
                    'order_id' => $orderId,
                    'local_order_id' => $order->getId(),
                ]);
            }
        } catch (\Throwable $e) {
            self::$logger->warning('iFood order auto-confirm failed', [
                'order_id' => $orderId,
                'local_order_id' => $order->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
    // FATURAS DE RECEBIMENTO (Pagamentos)
    // Para cada método de pagamento, cria fatura de recebimento no banco
    private function addReceiveInvoices(Order $order, array $payments)
    {
        $iFoodWallet = $this->walletService->discoverWallet($order->getProvider(), self::$app);
        $status = $this->statusService->discoveryStatus('closed', 'paid', 'invoice');
        foreach ($payments as $payment)
            $this->invoiceService->createInvoiceByOrder($order, $payment['value'], $payment['prepaid'] ? $status : null, new DateTime(), null, $iFoodWallet);
    }

    // ENTREGA
    // Define endereço de entrega e, se entrega for por terceiros, cria taxa de entrega
    private function addDelivery(Order &$order, array $orderDetails)
    {
        $delivery = $orderDetails['delivery'];
        $deliveryAddress = $delivery['deliveryAddress'];
        if ($delivery['deliveredBy'] != 'MERCHANT')
            $this->addDeliveryFee($order, $orderDetails['total']);

        $deliveryAddress = $this->addressService->discoveryAddress(
            $order->getClient(),
            (int) $deliveryAddress['postalCode'],
            (int) $deliveryAddress['streetNumber'],
            $deliveryAddress['streetName'],
            $deliveryAddress['neighborhood'],
            $deliveryAddress['city'],
            $deliveryAddress['state'],
            $deliveryAddress['country'],
            $deliveryAddress['complement'],
            (int) $deliveryAddress['coordinates']['latitude'],
            (int) $deliveryAddress['coordinates']['longitude'],
        );

        $order->setAddressDestination($deliveryAddress);
    }

    // TAXA DE ENTREGA
    // Cria fatura para taxa de entrega (cobrada do restaurante para o iFood)
    private function addDeliveryFee(Order &$order, array $payments)
    {
        $iFoodWallet = $this->walletService->discoverWallet($order->getProvider(), self::$app);
        $status = $this->statusService->discoveryStatus('closed', 'paid', 'invoice');
        $order->setRetrieveContact(self::$foodPeople);

        $this->invoiceService->createInvoice(
            $order,
            $order->getProvider(),
            self::$foodPeople,
            $payments['deliveryFee'],
            $status,
            new DateTime(),
            $iFoodWallet,
            $iFoodWallet
        );
    }

    // TAXAS ADICIONAIS
    // Cria fatura para taxas/comissões do iFood
    private function addFees(Order $order, array $payments)
    {
        $status = $this->statusService->discoveryStatus('closed', 'paid', 'invoice');
        $iFoodWallet = $this->walletService->discoverWallet($order->getProvider(), self::$app);
        $this->invoiceService->createInvoice(
            $order,
            $order->getProvider(),
            self::$foodPeople,
            $payments['additionalFees'],
            $status,
            new DateTime(),
            $iFoodWallet,
            $iFoodWallet
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
        } catch (Exception $e) {
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

            $token = $this->getAccessToken();
            if (!$token) {
                self::$logger->error('iFood order details request skipped because token is unavailable', [
                    'order_id' => $orderId,
                ]);
                return null;
            }

            $response = $this->httpClient->request('GET', self::API_BASE_URL . '/order/v1.0/orders/' . rawurlencode($orderId), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                self::$logger->error('iFood order details request failed', [
                    'order_id' => $orderId,
                    'status' => $response->getStatusCode(),
                    'response' => $response->getContent(false),
                ]);
                return null;
            }

            $data = $response->toArray(false);
            return is_array($data) ? $data : null;
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
        if (empty($customerData['name']) || empty($customerData['phone'])) {
            self::$logger->warning('Dados do cliente incompletos', ['customer' => $customerData]);
            return null;
        }

        $codClienteiFood = $customerData['id'];

        $client = $this->extraDataService->getEntityByExtraData(self::$app, 'code', $codClienteiFood, People::class);

        $phone = [
            'ddd' => '11',
            'phone' => $customerData['phone']['number']
        ];

        $document = $customerData['documentNumber'];

        if (!$client)
            $client = $this->peopleService->discoveryPeople($document, null, $phone, $customerData["name"]);

        $this->peopleService->discoveryLink($provider, $client, 'client');

        return $this->discoveryFoodCode($client, $codClienteiFood);
    }

    // DESCOBERTA/CRIAÇÃO DO PRODUTO
    // Busca produto existente por múltiplas chaves (iFood ID, código externo, EAN, nome)
    // Se não encontrar, cria novo produto. Se tem pai, associa como grupo/componente
    private function discoveryProduct(Order $order, array $item, ?Product $parentProduct = null, string $productType = 'product'): Product
    {
        $codProductiFood = $item['id'];
        $product = $this->extraDataService->getEntityByExtraData(self::$app, 'code', $codProductiFood, Product::class);

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
                $productGroupProduct = new ProductGroupProduct();
                $productGroupProduct->setProduct($parentProduct);
                $productGroupProduct->setProductChild($product);
                $productGroupProduct->setProductType($productType);
                $productGroupProduct->setProductGroup($productGroup);
                $productGroupProduct->setQuantity($item['quantity']);
                $productGroupProduct->setPrice($item['unitPrice']);
                $this->entityManager->persist($productGroupProduct);
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

    // HANDLER DE MUDANÇA DE ENTIDADE
    // Quando um pedido do iFood muda de status, dispara sincronização com o iFood
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

    private function resolveRemoteOrderId(Order $order): ?string
    {
        $orderId = $this->normalizeString($this->discoveryFoodCodeByEntity($order));
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
        ?string $remoteStateOnSuccess = null
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

        $result = $this->persistOrderActionResult(
            $order,
            'cancel',
            $this->cancelByShop($orderId, $cancellationCode),
            'cancelled'
        );

        if ((string) ($result['errno'] ?? '') === '0' && ($reason !== null || $cancellationCode !== null)) {
            try {
                $this->persistOrderIntegrationState($order, [
                    'cancel_reason' => $cancellationCode ?? $reason,
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

    public function performReadyAction(Order $order): array
    {
        $this->init();
        $orderId = $this->resolveRemoteOrderId($order);
        if (!$orderId) {
            return $this->buildUnavailableOrderActionResponse('Pedido iFood sem identificador remoto.');
        }

        return $this->persistOrderActionResult(
            $order,
            'ready',
            $this->readyOrder($orderId),
            'ready'
        );
    }

    public function performDeliveredAction(Order $order): array
    {
        $this->init();
        $orderId = $this->resolveRemoteOrderId($order);
        if (!$orderId) {
            return $this->buildUnavailableOrderActionResponse('Pedido iFood sem identificador remoto.');
        }

        return $this->persistOrderActionResult(
            $order,
            'delivered',
            $this->deliveredOrder($orderId),
            'dispatching'
        );
    }

    private function callIfoodOrderAction(string $orderId, string $actionPath, array $payload = []): ?array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            self::$logger->warning('iFood order action skipped because token is unavailable', [
                'order_id' => $orderId,
                'action' => $actionPath,
            ]);
            return null;
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                self::API_BASE_URL . '/order/v1.0/orders/' . rawurlencode($orderId) . $actionPath,
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                ]
            );

            return [
                'status' => $response->getStatusCode(),
                'body' => $response->toArray(false),
            ];
        } catch (\Throwable $e) {
            self::$logger->error('iFood order action error', [
                'order_id' => $orderId,
                'action' => $actionPath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function cancelByShop(string $orderId, ?string $cancellationCode = null): ?array
    {
        $payload = [];
        if ($cancellationCode !== null && $cancellationCode !== '') {
            $payload['cancellationCode'] = $cancellationCode;
        }
        return $this->callIfoodOrderAction($orderId, '/requestCancellation', $payload);
    }

    private function confirmOrder(string $orderId): ?array
    {
        return $this->callIfoodOrderAction($orderId, '/confirm');
    }

    private function fetchIfoodCancellationReasons(): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return [];
        }
        try {
            $response = $this->httpClient->request('GET', self::API_BASE_URL . '/order/v1.0/cancellation/reasons', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
            ]);
            if ($response->getStatusCode() !== 200) {
                return [];
            }
            $data = $response->toArray(false);
            return is_array($data['cancellationReasons'] ?? null) ? $data['cancellationReasons'] : [];
        } catch (\Throwable $e) {
            self::$logger->error('iFood cancellation reasons fetch failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function getIfoodCancellationReasons(): array
    {
        $this->init();
        $raw = $this->fetchIfoodCancellationReasons();
        return array_map(fn(array $r) => [
            'reason_id'            => $this->normalizeString($r['cancelCode'] ?? null),
            'description'          => $this->normalizeString($r['description'] ?? null),
            'applicable'           => true,
            'requires_description' => false,
        ], $raw);
    }

    public function performConfirmAction(Order $order): array
    {
        $this->init();
        $orderId = $this->resolveRemoteOrderId($order);
        if (!$orderId) {
            return $this->buildUnavailableOrderActionResponse('Pedido iFood sem identificador remoto.');
        }
        return $this->persistOrderActionResult($order, 'confirm', $this->confirmOrder($orderId), 'confirmed');
    }

    private function readyOrder(string $orderId): ?array
    {
        return $this->callIfoodOrderAction($orderId, '/readyToPickup');
    }

    private function deliveredOrder(string $orderId): ?array
    {
        return $this->callIfoodOrderAction($orderId, '/dispatch');
    }

    // SINCRONIZACAO DE STATUS COM iFOOD
    // Envia para iFood o novo status do pedido (pronto, entregue, cancelado)
    public function changeStatus(Order $order)
    {
        $orderId = $this->normalizeString($this->discoveryFoodCodeByEntity($order));
        if ($orderId === '') {
            return null;
        }

        $realStatus = strtolower(trim((string) $order->getStatus()->getRealStatus()));

        match ($realStatus) {
            'cancelled', 'canceled' => $this->cancelByShop($orderId),
            'ready' => $this->readyOrder($orderId),
            'delivered', 'closed' => $this->deliveredOrder($orderId),
            default => null,
        };

        return null;
    }
}

