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
use ControleOnline\Entity\Status;
use ControleOnline\Entity\User;
use ControleOnline\Service\Client\WebsocketClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use ControleOnline\Service\LoggerService;
use DateTime;
use ControleOnline\Event\EntityChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class iFoodService extends DefaultFoodService implements EventSubscriberInterface
{
    private const APP_CONTEXT = 'iFood';
    private const API_BASE_URL = 'https://merchant-api.ifood.com.br';
    private const SELF_DELIVERY_CONFIRMATION_URL = 'https://confirmacao-entrega-propria.ifood.com.br/';
    private const MAX_IMAGE_UPLOAD_BYTES = 5242880; // 5MB
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

        if ($this->isCancellationEventCode($eventCode)) {
            $this->applyLocalCanceledStatus($order);
        }

        if ($this->isConclusionEventCode($eventCode)) {
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
        $normalized = strtoupper(str_replace(['.', '-', ' '], '_', $this->normalizeString($eventCode)));
        if ($normalized === '') {
            return 'unknown';
        }

        return match ($normalized) {
            'PLACED', 'ORDER_CREATED', 'CREATED', 'PENDING', 'ORDER_PENDING' => 'new',
            'CONFIRMED', 'ORDER_CONFIRMED', 'ACCEPTED', 'ORDER_ACCEPTED' => 'confirmed',
            'STARTED', 'PREPARING', 'START_PREPARATION', 'ORDER_PREPARATION_STARTED', 'ORDER_IN_PREPARATION' => 'preparing',
            'READY', 'READY_TO_PICKUP', 'ORDER_READY_TO_PICKUP' => 'ready',
            'DISPATCHING', 'DISPATCHED', 'ORDER_DISPATCHED', 'ORDER_PICKED_UP', 'ORDER_IN_TRANSIT', 'DELIVERY_STARTED' => 'dispatching',
            'CONCLUDED', 'ORDER_CONCLUDED', 'ORDER_FINISHED', 'DELIVERY_CONCLUDED' => 'concluded',
            'CANCELLED', 'CANCELED', 'ORDER_CANCELLED', 'ORDER_CANCELED', 'ORDER_CANCELLED_BY_CUSTOMER', 'ORDER_CANCELED_BY_CUSTOMER' => 'cancelled',
            'CANCELLATION_REQUESTED', 'ORDER_CANCELLATION_REQUESTED' => 'cancellation_requested',
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

    private function extractOrderDetailSnapshot(array $orderPayload): array
    {
        if (!$orderPayload) {
            return [];
        }

        $delivery = is_array($orderPayload['delivery'] ?? null) ? $orderPayload['delivery'] : [];
        $deliveryAddress = is_array($delivery['deliveryAddress'] ?? null) ? $delivery['deliveryAddress'] : [];
        $customer = is_array($orderPayload['customer'] ?? null) ? $orderPayload['customer'] : [];
        $phone = is_array($customer['phone'] ?? null) ? $customer['phone'] : [];

        $streetName = $this->normalizeString($deliveryAddress['streetName'] ?? null);
        $streetNumber = $this->normalizeString($deliveryAddress['streetNumber'] ?? null);
        $district = $this->normalizeString($deliveryAddress['neighborhood'] ?? null);
        $city = $this->normalizeString($deliveryAddress['city'] ?? null);
        $state = $this->normalizeString($deliveryAddress['state'] ?? null);
        $postalCode = $this->normalizeString($deliveryAddress['postalCode'] ?? null);
        $reference = $this->normalizeString($deliveryAddress['reference'] ?? null);
        $complement = $this->normalizeString($deliveryAddress['complement'] ?? null);

        $addressDisplay = $this->composeAddressDisplayFromPieces(
            $streetName !== '' ? $streetName : null,
            $streetNumber !== '' ? $streetNumber : null,
            $district !== '' ? $district : null,
            $city !== '' ? $city : null
        );

        $additionalInfo = $orderPayload['additionalInfo'] ?? null;
        $remark = '';
        if (is_array($additionalInfo)) {
            $remark = $this->normalizeString($additionalInfo['notes'] ?? $additionalInfo['observation'] ?? null);
        } else {
            $remark = $this->normalizeString($additionalInfo);
        }

        if ($remark === '') {
            $remark = $this->normalizeString($orderPayload['orderComment'] ?? null);
        }

        $deliveredBy = strtoupper($this->normalizeString($delivery['deliveredBy'] ?? null));
        $deliveryMode = $this->normalizeString($delivery['mode'] ?? ($delivery['deliveryMode'] ?? null));
        $pickupCode = $this->extractOrderPayloadValue($orderPayload, [
            ['pickup', 'code'],
            ['delivery', 'pickupCode'],
            ['delivery', 'pickup_code'],
            ['pickupCode'],
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
            'delivered_by' => $deliveredBy,
            'delivery_mode' => $deliveryMode,
            'pickup_code' => $pickupCode,
            'handover_code' => $pickupCode,
            'locator' => $locator,
            'handover_page_url' => $handoverPageUrl,
            'handover_confirmation_url' => $handoverConfirmationUrl,
            'virtual_phone' => $this->normalizeString($phone['localizer'] ?? null),
            'customer_name' => $this->normalizeString($customer['name'] ?? null),
            'customer_phone' => $this->normalizeString($phone['number'] ?? null),
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
        ];

        if (($snapshot['address_display'] ?? '') === '' && $addressDisplay !== '') {
            $snapshot['address_display'] = $addressDisplay;
        }

        return array_filter(
            $snapshot,
            static fn($value) => $value !== null && $value !== ''
        );
    }

    private function persistIncomingEventState(Order $order, Integration $integration, array $payload): void
    {
        $eventCode = $this->resolveEventCode($payload);
        $meta = $this->extractWebhookMeta($payload);
        $eventTimestamp = $this->extractEventTimestamp($payload);
        $orderId = $this->normalizeString($payload['orderId'] ?? ($meta['order_id'] ?? null));
        $merchantId = $this->normalizeString($payload['merchantId'] ?? ($meta['shop_id'] ?? null));

        $statePayload = [
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
        ];

        $orderPayload = is_array($payload['order'] ?? null) ? $payload['order'] : [];
        if ($orderPayload) {
            $statePayload = array_merge($statePayload, $this->extractOrderDetailSnapshot($orderPayload));
        }

        $this->persistOrderIntegrationState($order, $statePayload);
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
            'delivered_by' => $this->getIfoodExtraDataValue('Order', $orderId, 'delivered_by'),
            'delivery_mode' => $this->getIfoodExtraDataValue('Order', $orderId, 'delivery_mode'),
            'pickup_code' => $this->getIfoodExtraDataValue('Order', $orderId, 'pickup_code'),
            'handover_code' => $this->getIfoodExtraDataValue('Order', $orderId, 'handover_code'),
            'locator' => $this->getIfoodExtraDataValue('Order', $orderId, 'locator'),
            'handover_page_url' => $this->getIfoodExtraDataValue('Order', $orderId, 'handover_page_url'),
            'handover_confirmation_url' => $this->getIfoodExtraDataValue('Order', $orderId, 'handover_confirmation_url'),
            'virtual_phone' => $this->getIfoodExtraDataValue('Order', $orderId, 'virtual_phone'),
            'customer_name' => $this->getIfoodExtraDataValue('Order', $orderId, 'customer_name'),
            'customer_phone' => $this->getIfoodExtraDataValue('Order', $orderId, 'customer_phone'),
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

            if (is_array($payload['order'] ?? null)) {
                $snapshot = $this->extractOrderDetailSnapshot($payload['order']);
                foreach ($snapshot as $fieldName => $fieldValue) {
                    if (($state[$fieldName] ?? '') === '' && $fieldValue !== '') {
                        $state[$fieldName] = $fieldValue;
                    }
                }
            }
        }

        return $state;
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
                    $remoteByEc[$ec] = [
                        'item_id' => $this->normalizeString($item['id'] ?? ''),
                        'status'  => $this->normalizeString($item['status'] ?? 'AVAILABLE'),
                    ];
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

        $remoteEntry = $remoteByEc[$ec] ?? null;

        return [
            'id'               => $productId,
            'name'             => $name,
            'description'      => trim((string) ($row['description'] ?? '')),
            'price'            => $price,
            'type'             => (string) ($row['type'] ?? ''),
            'category'         => $categoryId !== null ? ['id' => $categoryId, 'name' => $categoryName] : null,
            'eligible'         => empty($blockers),
            'blockers'         => $blockers,
            'published_remotely' => $remoteEntry !== null,
            'ifood_item_id'    => $remoteEntry['item_id'] ?? null,
            'ifood_status'     => $remoteEntry['status'] ?? null,
            'cover_image_url'  => $this->buildPublicFileDownloadUrl($row['cover_file_id'] ?? null),
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

            $result = $this->upsertIfoodCatalogItemV2($merchantId, $prod, $existing, $categoryId);
            if ($result['ok']) {
                $pushed++;
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
                pf.file_id AS cover_file_id,
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
            LEFT JOIN product_file pf ON pf.id = (
                SELECT MIN(pf2.id)
                FROM product_file pf2
                WHERE pf2.product_id = p.id
            )
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

    private function normalizeImageMimeType(?string $contentType): ?string
    {
        $normalized = strtolower(trim((string) $contentType));
        if ($normalized === '') {
            return null;
        }

        $normalized = explode(';', $normalized)[0] ?? $normalized;
        $normalized = trim($normalized);

        if ($normalized === 'image/jpg') {
            return 'image/jpeg';
        }

        return in_array($normalized, ['image/jpeg', 'image/png'], true) ? $normalized : null;
    }

    private function buildIfoodUploadImageDataUri(string $imageUrl): ?string
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
            $mimeType = $this->normalizeImageMimeType($contentType);
            if (!$mimeType) {
                self::$logger->warning('iFood image fetch returned unsupported mime type', [
                    'image_url' => $imageUrl,
                    'content_type' => $contentType,
                ]);
                return null;
            }

            $binary = $response->getContent(false);
            $sizeBytes = strlen($binary);
            if ($sizeBytes <= 0 || $sizeBytes > self::MAX_IMAGE_UPLOAD_BYTES) {
                self::$logger->warning('iFood image fetch returned invalid size for upload', [
                    'image_url' => $imageUrl,
                    'size_bytes' => $sizeBytes,
                    'max_bytes' => self::MAX_IMAGE_UPLOAD_BYTES,
                ]);
                return null;
            }

            return 'data:' . $mimeType . ';base64,' . base64_encode($binary);
        } catch (\Throwable $e) {
            self::$logger->warning('iFood image fetch exception before upload', [
                'image_url' => $imageUrl,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
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

    private function uploadIfoodCatalogImageAndResolvePath(string $merchantId, string $sourceImageUrl): ?string
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

        $dataUri = $this->buildIfoodUploadImageDataUri($sourceImageUrl);
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

    private function upsertIfoodCatalogItemV2(string $merchantId, array $product, ?array $existing, string $categoryId): array
    {
        $token = $this->getAccessToken();
        if (!$token) return ['ok' => false, 'http_status' => null, 'ifood_body' => null, 'error' => 'Token indisponivel'];

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
        $sourceImageUrl = $this->buildPublicFileDownloadUrl($product['cover_file_id'] ?? null);
        if ($sourceImageUrl) {
            $uploadedImagePath = $this->uploadIfoodCatalogImageAndResolvePath($merchantId, $sourceImageUrl);
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
            'products'     => [$productBody],
            'optionGroups' => [],
            'options'      => [],
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
                if ($attemptIndex === 0 && count($attempts) > 1) {
                    self::$logger->warning('iFood catalog v2 upsert failed with imagePath, retrying without imagePath', [
                        'status'     => $status,
                        'product_id' => $product['id'],
                        'body'       => $body,
                    ]);
                    continue;
                }
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                if ($attemptIndex === 0 && count($attempts) > 1) {
                    self::$logger->warning('iFood catalog v2 upsert exception with imagePath, retrying without imagePath', [
                        'product_id' => $product['id'],
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
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

        $provider = $this->findEntityByExtraData('People', 'code', $merchantId, People::class);

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
        $paymentMethods = is_array($orderDetails['payments']['methods'] ?? null) ? $orderDetails['payments']['methods'] : [];
        $allPrepaid = !empty($paymentMethods) && array_reduce(
            $paymentMethods,
            fn(bool $carry, array $m) => $carry && ($m['prepaid'] ?? false),
            true
        );
        $status = $allPrepaid
            ? $this->resolveMappedOrderStatus('paid', 'open', $orderId, $merchantId)
            : $this->resolveMappedOrderStatus('waiting payment', 'pending', $orderId, $merchantId);
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

        $extendedState = ['id' => $orderId, 'code' => $orderId, 'merchant_id' => $merchantId,
            'last_event_type' => $snapshotKey, 'last_event_at' => $this->extractEventTimestamp($json),
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

        $this->discoveryFoodCode($order, $orderId, 'id');
        $this->discoveryFoodCode($order, $orderId, 'code');
        $this->printOrder($order);
        $this->autoConfirmOrder($order, $orderId);
        return $order;
    }

    private function resolveMappedOrderStatus(string $status, string $realStatus, string $orderId, string $merchantId): ?Status
    {
        $resolved = $this->findMappedOrderStatus($status, $realStatus);
        if ($resolved instanceof Status) {
            return $resolved;
        }

        self::$logger->error('iFood order status mapping not found in local status table', [
            'order_id' => $orderId,
            'merchant_id' => $merchantId,
            'status' => $status,
            'real_status' => $realStatus,
        ]);

        return null;
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
        $delivery = is_array($orderDetails['delivery'] ?? null) ? $orderDetails['delivery'] : [];
        $deliveryAddress = is_array($delivery['deliveryAddress'] ?? null) ? $delivery['deliveryAddress'] : [];
        if (!$deliveryAddress) {
            return;
        }

        if ($this->normalizeString($delivery['deliveredBy'] ?? null) !== 'MERCHANT') {
            $this->addDeliveryFee($order, $orderDetails['total']);
        }

        $latitude = (int) ($deliveryAddress['coordinates']['latitude'] ?? 0);
        $longitude = (int) ($deliveryAddress['coordinates']['longitude'] ?? 0);
        $postalCode = (int) preg_replace('/\D+/', '', (string) ($deliveryAddress['postalCode'] ?? ''));

        $deliveryAddressEntity = $this->addressService->discoveryAddress(
            $order->getClient(),
            $postalCode,
            (int) ($deliveryAddress['streetNumber'] ?? 0),
            $deliveryAddress['streetName'] ?? null,
            $deliveryAddress['neighborhood'] ?? null,
            $deliveryAddress['city'] ?? null,
            $deliveryAddress['state'] ?? null,
            $deliveryAddress['country'] ?? null,
            $deliveryAddress['complement'] ?? null,
            $latitude,
            $longitude,
        );

        $order->setAddressDestination($deliveryAddressEntity);
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
            $maxAttempts = 4;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $token = $this->getAccessToken();
                if (!$token) {
                    self::$logger->warning('iFood order details request skipped because token is unavailable', [
                        'order_id' => $orderId,
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                    ]);

                    if ($attempt < $maxAttempts) {
                        usleep(300000 * $attempt);
                        continue;
                    }
                    break;
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
                            'attempt' => $attempt,
                            'max_attempts' => $maxAttempts,
                            'response' => $rawBody,
                        ]);

                        $isRetryableStatus = in_array($statusCode, [401, 404, 409, 425, 429, 500, 502, 503, 504], true);
                        if ($statusCode === 401) {
                            // Force token refresh on next attempt.
                            self::$authTokenCache = [];
                        }

                        if ($isRetryableStatus && $attempt < $maxAttempts) {
                            usleep(300000 * $attempt);
                            continue;
                        }
                    } else {
                        $data = $this->decodeIfoodActionResponseBody((string) $rawBody);
                        return is_array($data) ? $data : null;
                    }
                } catch (\Throwable $e) {
                    self::$logger->warning('iFood order details request endpoint error', [
                        'order_id' => $orderId,
                        'endpoint' => $endpoint,
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'error' => $e->getMessage(),
                    ]);

                    if ($attempt < $maxAttempts) {
                        usleep(300000 * $attempt);
                        continue;
                    }
                }
            }

            self::$logger->error('iFood order details request failed', [
                'order_id' => $orderId,
                'endpoint' => $endpoint,
            ]);
            return null;
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

        $client = $this->findEntityByExtraData('People', 'code', $codClienteiFood, People::class);

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
            'cancelled'
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
                'confirmed'
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

        return $this->persistOrderActionResult(
            $order,
            'ready',
            $this->dispatchOrderByDeliveryMode($order, $orderId),
            'dispatching'
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
            'preparing'
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
                $response = $this->httpClient->request(
                    'POST',
                    $endpoint,
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $token,
                            'Content-Type' => 'application/json',
                        ],
                        'json' => $this->normalizeIfoodRequestPayload($payload),
                    ]
                );

                $statusCode = $response->getStatusCode();
                $rawBody = $response->getContent(false);

                return [
                    'status' => $statusCode,
                    'body' => $this->decodeIfoodActionResponseBody((string) $rawBody),
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

            $response = $this->httpClient->request(
                'POST',
                self::API_BASE_URL . '/shipping/v1.0/orders/' . rawurlencode($orderId) . $actionPath,
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $this->normalizeIfoodRequestPayload($payload),
                ]
            );

            $statusCode = $response->getStatusCode();
            $rawBody = $response->getContent(false);

            return [
                'status' => $statusCode,
                'body' => $this->decodeIfoodActionResponseBody((string) $rawBody),
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
            $shippingResponse = $this->callIfoodShippingAction($orderId, '/dispatch');
            if (!$this->shouldFallbackActionEndpoint($shippingResponse)) {
                return $shippingResponse;
            }

            $orderResponse = $this->callIfoodOrderAction($orderId, '/dispatch');
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
        $response = $this->callIfoodOrderAction($orderId, '/accept');
        if (!$this->shouldFallbackActionEndpoint($response)) {
            return $response;
        }

        $fallbackResponse = $this->callIfoodOrderAction($orderId, '/confirm');
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
            $endpoints[] = self::API_BASE_URL . '/order/v1.0/orders/' . $encodedOrderId . '/cancellationReasons';
        }
        $endpoints[] = self::API_BASE_URL . '/order/v1.0/cancellation/reasons';

        try {
            foreach ($endpoints as $endpoint) {
                $response = $this->httpClient->request('GET', $endpoint, [
                    'headers' => ['Authorization' => 'Bearer ' . $token],
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
        $orderId = $this->resolveRemoteOrderId($order) ?? '';
        if ($orderId === '') {
            return null;
        }

        $realStatus = strtolower(trim((string) $order->getStatus()->getRealStatus()));

        match ($realStatus) {
            'cancelled', 'canceled' => $this->cancelByShop($orderId),
            'ready' => $this->dispatchOrderByDeliveryMode($order, $orderId),
            'delivered', 'closed' => null,
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
}

