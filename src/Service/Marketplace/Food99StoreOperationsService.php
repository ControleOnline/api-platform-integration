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
use ControleOnline\Event\Food99DelegationEvent;
use ControleOnline\Service\Client\Food99Client;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationHandlerInterface;
use ControleOnline\Service\Marketplace\AbstractMarketplaceService;
use ControleOnline\Service\Marketplace\MarketplaceIntegrationStateProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceLogisticsQuoteProviderInterface;
use ControleOnline\Service\Marketplace\MarketplaceOrderSnapshotProviderInterface;
use ControleOnline\Event\EntityChangedEvent;
use DateTime;
use DateTimeInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

class Food99StoreOperationsService extends AbstractMarketplaceService implements EventSubscriberInterface
{
    private const APP_CONTEXT = Order::APP_FOOD99;
    private const SHOP_CANCEL_REASONS = [
        ['reason_id' => 1010, 'description' => 'Item sold out', 'applicable_to' => 'all'],
        ['reason_id' => 1020, 'description' => 'Store closed for the day', 'applicable_to' => 'all'],
        ['reason_id' => 1030, 'description' => 'Store too busy to prepare order', 'applicable_to' => 'all'],
        ['reason_id' => 1040, 'description' => 'Major accident or utility outage', 'applicable_to' => 'all'],
        ['reason_id' => 1050, 'description' => 'Canceled due to customer issue', 'applicable_to' => 'all'],
        ['reason_id' => 1060, 'description' => 'No courier available', 'applicable_to' => 'self_delivery'],
        ['reason_id' => 1070, 'description' => 'Menu needs to be updated', 'applicable_to' => 'all'],
        ['reason_id' => 1071, 'description' => 'Order is outside the delivery area', 'applicable_to' => 'self_delivery'],
        ['reason_id' => 1072, 'description' => 'Order address is in an unsafe area', 'applicable_to' => 'self_delivery'],
        ['reason_id' => 1073, 'description' => 'Suspected fraud or prank', 'applicable_to' => 'all'],
        ['reason_id' => 1074, 'description' => 'Questions about fees or promotions', 'applicable_to' => 'all'],
        ['reason_id' => 1080, 'description' => 'Other reason', 'applicable_to' => 'all'],
    ];

    private ?EventDispatcherInterface $eventDispatcher = null;

    protected function getMarketplaceApp(): string
    {
        return self::APP_CONTEXT;
    }

    #[Required]
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            Food99DelegationEvent::class => 'onFood99Delegation',
        ];
    }

    public function onFood99Delegation(Food99DelegationEvent $event): void
    {
        switch ($event->action) {
            case Food99DelegationEvent::ACTION_STORE_PERSIST_PROVIDER_LAST_ERROR:
                $provider = $event->provider;
                if (!$provider instanceof People) {
                    return;
                }
                $this->persistProviderLastError(
                    $provider,
                    $event->payload['code'] ?? null,
                    $event->payload['message'] ?? null
                );
                $event->markHandled(null);
                return;

            case Food99DelegationEvent::ACTION_STORE_PERSIST_PROVIDER_MENU_UPLOAD_SUBMISSION:
                $provider = $event->provider;
                if (!$provider instanceof People) {
                    return;
                }
                $this->persistProviderMenuUploadSubmission(
                    $provider,
                    is_array($event->payload['response'] ?? null) ? $event->payload['response'] : [],
                    $event->payload['task_id'] ?? null
                );
                $event->markHandled(null);
                return;

            case Food99DelegationEvent::ACTION_STORE_NORMALIZE_MENU_TASK_RESPONSE:
                $result = $this->normalizeMenuTaskResponse(
                    is_array($event->payload['response'] ?? null) ? $event->payload['response'] : [],
                    $event->payload['fallback_task_id'] ?? null
                );
                $event->markHandled($result);
                return;

            case Food99DelegationEvent::ACTION_STORE_PERSIST_PROVIDER_MENU_TASK_STATE:
                $provider = $event->provider;
                if (!$provider instanceof People) {
                    return;
                }
                $result = $this->persistProviderMenuTaskState(
                    $provider,
                    is_array($event->payload['response'] ?? null) ? $event->payload['response'] : [],
                    $event->payload['fallback_task_id'] ?? null
                );
                $event->markHandled($result);
                return;

            case Food99DelegationEvent::ACTION_STORE_GET_STORED_INTEGRATION_STATE:
                $provider = $event->provider;
                if (!$provider instanceof People) {
                    return;
                }
                $result = $this->getStoredIntegrationState($provider);
                $event->markHandled($result);
                return;
        }
    }

    private function dispatchFood99DelegationEvent(Food99DelegationEvent $event): Food99DelegationEvent
    {
        if ($this->eventDispatcher instanceof EventDispatcherInterface) {
            $this->eventDispatcher->dispatch($event);
        }

        return $event;
    }

    private function normalizeIncomingFood99Value(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function normalizeFood99Money(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return round(((float) $value) / 100, 2);
    }

    private function normalizeExtraDataValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }

    private function getFood99ExtraDataValue(string $entityName, int $entityId, string $fieldName = 'code'): ?string
    {
        return $this->extraDataService->getExtraDataValue(
            Order::APP_FOOD99,
            $entityName,
            $entityId,
            $fieldName
        );
    }

    private function upsertFood99ExtraDataValue(
        string $entityName,
        int $entityId,
        string $fieldName,
        mixed $value,
        string $fieldType = 'text'
    ): void {
        $this->extraDataService->upsertExtraDataValue(
            Order::APP_FOOD99,
            $entityName,
            $entityId,
            $fieldName,
            $this->normalizeExtraDataValue($value),
            $fieldType,
            self::APP_CONTEXT
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

    private function call99AppEndpointWithResponse(string $method, string $uri, array $payload = []): ?array
    {
        $client = $this->resolveFood99Client();
        if (!$client instanceof Food99Client) {
            return null;
        }

        return $client->callAppEndpointWithResponse($method, $uri, $payload);
    }

    private function call99StoreEndpointWithResponse(string $method, string $uri, array $payload = [], ?People $provider = null): ?array
    {
        $client = $this->resolveFood99Client();
        if (!$client instanceof Food99Client) {
            return null;
        }

        return $client->callStoreEndpointWithResponse($method, $uri, $payload, $provider);
    }

    private function call99StoreMultipartEndpointWithResponse(string $method, string $uri, array $payload = [], ?People $provider = null): ?array
    {
        $client = $this->resolveFood99Client();
        if (!$client instanceof Food99Client) {
            return null;
        }

        return $client->callStoreMultipartEndpointWithResponse($method, $uri, $payload, $provider);
    }

    public function decodeOrderOtherInformationsValue(mixed $value): array
    {
        return $this->decodeEntityOtherInformationsValue($value);
    }

    public function getDecodedOrderOtherInformations(Order $order): array
    {
        return $this->getDecodedEntityOtherInformations($order);
    }

    public function resolveFood99QuoteSourceOrder(Order $order): Order
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

    public function resolveFood99QuotePickupAddress(Order $order, Order $sourceOrder): ?Address
    {
        $pickupAddress = $this->resolveAddressCandidate($order->getAddressOrigin());
        if ($pickupAddress instanceof Address) {
            return $pickupAddress;
        }

        $pickupAddress = $this->resolveAddressCandidate($sourceOrder->getAddressOrigin());

        return $pickupAddress instanceof Address ? $pickupAddress : null;
    }

    public function resolveFood99QuoteDropoffAddress(Order $order, Order $sourceOrder): ?Address
    {
        $dropoffAddress = $this->resolveAddressCandidate($order->getAddressDestination());
        if ($dropoffAddress instanceof Address) {
            return $dropoffAddress;
        }

        $dropoffAddress = $this->resolveAddressCandidate($sourceOrder->getAddressDestination());

        return $dropoffAddress instanceof Address ? $dropoffAddress : null;
    }

    public function resolveFood99PrimaryAddress(?People $people): ?Address
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

    public function getStoredFood99QuoteState(Order $order): array
    {
        $otherInformations = $this->getDecodedEntityOtherInformations($order);
        $logistics = $otherInformations['logistics'] ?? [];

        return is_array($logistics) ? $logistics : [];
    }

    public function markProductsCatalogSynced(People $provider, array $productIds, bool $dispatch = true): void
    {
        if ($dispatch) {
            $event = $this->dispatchFood99DelegationEvent(new Food99DelegationEvent(
                Food99DelegationEvent::ACTION_CATALOG_MARK_PRODUCTS_SYNCED,
                $provider,
                ['product_ids' => $productIds]
            ));

            if ($event->handled) {
                return;
            }
        }
    }

    public function syncIntegrationState(People $provider, bool $dispatch = true): array
    {
        if ($dispatch) {
            $event = $this->dispatchFood99DelegationEvent(new Food99DelegationEvent(
                Food99DelegationEvent::ACTION_CATALOG_SYNC_INTEGRATION_STATE,
                $provider
            ));

            if ($event->handled && is_array($event->result)) {
                return $event->result;
            }
        }

        return [];
    }

    public function persistProviderIntegrationState(People $provider, array $fields): void
    {
        $legacyFields = [];
        $stateFields = [];

        foreach ($fields as $fieldName => $value) {
            $normalizedFieldName = trim((string) $fieldName);
            if ($normalizedFieldName === '') {
                continue;
            }

            if ($normalizedFieldName === 'code') {
                $legacyFields[$normalizedFieldName] = $value;
                continue;
            }

            $stateFields[$normalizedFieldName] = $value;
        }

        if ($stateFields !== []) {
            $this->mergeEntityOtherInformations($provider, self::APP_CONTEXT, $stateFields);
        }

        foreach ($legacyFields as $fieldName => $value) {
            $this->upsertFood99ExtraDataValue('People', (int) $provider->getId(), $fieldName, $value);
        }
    }

    public function persistProviderLastError(People $provider, mixed $code = null, mixed $message = null): void
    {
        $this->persistProviderIntegrationState($provider, [
            'last_error_code' => $code,
            'last_error_message' => $message,
        ]);
    }

    private function persistProviderStoreState(People $provider, array $storeData): void
    {
        $shopId = isset($storeData['shop_id']) ? (string) $storeData['shop_id'] : $this->resolveMarketplaceProviderCode($provider, self::APP_CONTEXT);
        $bizStatus = isset($storeData['biz_status']) ? (int) $storeData['biz_status'] : null;
        $subBizStatus = isset($storeData['sub_biz_status']) ? (int) $storeData['sub_biz_status'] : null;
        $storeStatus = isset($storeData['store_status']) ? (int) $storeData['store_status'] : null;

        $this->persistProviderIntegrationState($provider, [
            'code' => $shopId,
            'biz_status' => $bizStatus,
            'sub_biz_status' => $subBizStatus,
            'store_status' => $storeStatus,
            'remote_connected' => 1,
            'online' => $bizStatus === 1 ? 1 : 0,
            'last_sync_at' => date('Y-m-d H:i:s'),
            'last_error_code' => '',
            'last_error_message' => '',
        ]);
    }

    private function persistProviderMenuState(People $provider, array $menuData, mixed $taskId = null): void
    {
        $menus = is_array($menuData['menus'] ?? null) ? $menuData['menus'] : [];
        $items = is_array($menuData['items'] ?? null) ? $menuData['items'] : [];

        $this->persistProviderIntegrationState($provider, [
            'menu_count' => count($menus),
            'menu_item_count' => count($items),
            'last_menu_task_id' => $taskId,
            'last_sync_at' => date('Y-m-d H:i:s'),
            'remote_connected' => 1,
            'last_error_code' => '',
            'last_error_message' => '',
        ]);
    }

    private function syncPublishedProductsForProvider(People $provider, array $publishedItemIds): void
    {
        $publishedItemIds = array_values(array_unique(array_filter(array_map(
            fn(mixed $itemId) => $this->normalizeExtraDataValue($itemId),
            $publishedItemIds
        ))));
        $publishedItemIdSet = array_flip($publishedItemIds);
        $localCandidateIds = [];
        $syncedProductIds = [];

        foreach ($this->fetchMenuProducts($provider) as $row) {
            $productId = (int) ($row['id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $candidateId = trim((string) ($row['food99_code'] ?? '')) ?: (string) $productId;
            $localCandidateIds[] = $candidateId;
            $published = isset($publishedItemIdSet[$candidateId]);

            if ($published && empty($row['food99_code'])) {
                $this->persistLocalFoodCodeByEntity('Product', $productId, $candidateId);
            }

            $this->upsertFood99ExtraDataValue('Product', $productId, 'published', $published ? '1' : '0');
            if ($published) {
                $syncedProductIds[] = $productId;
            }
        }

        $remoteOnlyItemCount = count(array_diff($publishedItemIds, array_unique($localCandidateIds)));
        $this->persistProviderIntegrationState($provider, [
            'remote_only_item_count' => $remoteOnlyItemCount,
            'last_sync_at' => date('Y-m-d H:i:s'),
        ]);

        $this->markProductsCatalogSynced($provider, $syncedProductIds);
    }

    public function persistProviderMenuUploadSubmission(People $provider, array $response, mixed $taskId = null): void
    {
        $taskData = is_array($response['data'] ?? null) ? $response['data'] : [];
        $taskStatus = isset($taskData['status']) ? (string) $taskData['status'] : '0';
        $taskMessage = trim((string) ($taskData['message'] ?? 'waiting'));
        $publishState = $this->resolveMenuTaskProgressState($taskData);

        $this->persistProviderIntegrationState($provider, [
            'last_menu_task_id' => $taskId,
            'last_menu_task_status' => $taskStatus,
            'last_menu_task_message' => $taskMessage,
            'last_menu_task_checked_at' => date('Y-m-d H:i:s'),
            'last_menu_publish_state' => $publishState === 'completed' ? 'submitted' : $publishState,
            'last_error_code' => '',
            'last_error_message' => '',
        ]);
    }

    private function extractMenuTaskFailureMessage(array $taskData): ?string
    {
        foreach (($taskData['operationList'] ?? []) as $operation) {
            if (!is_array($operation)) {
                continue;
            }

            foreach (($operation['failedList'] ?? []) as $failedItem) {
                if (!is_array($failedItem)) {
                    continue;
                }

                $message = trim((string) ($failedItem['message'] ?? ''));
                if ($message !== '') {
                    return $message;
                }
            }
        }

        return null;
    }

    public function normalizeMenuTaskResponse(array $response, int|string|null $fallbackTaskId = null): array
    {
        if (!is_array($response['data'] ?? null)) {
            return $response;
        }

        $taskData = $response['data'];

        if (array_key_exists('taskID', $taskData) && $taskData['taskID'] !== null && $taskData['taskID'] !== '') {
            $taskData['taskID'] = (string) $taskData['taskID'];
        } elseif ($fallbackTaskId !== null && $fallbackTaskId !== '') {
            $taskData['taskID'] = (string) $fallbackTaskId;
        }

        if (array_key_exists('taskId', $taskData) && $taskData['taskId'] !== null && $taskData['taskId'] !== '') {
            $taskData['taskId'] = (string) $taskData['taskId'];
        }

        if (array_key_exists('appShopID', $taskData) && $taskData['appShopID'] !== null && $taskData['appShopID'] !== '') {
            $taskData['appShopID'] = (string) $taskData['appShopID'];
        }

        if (array_key_exists('app_shop_id', $taskData) && $taskData['app_shop_id'] !== null && $taskData['app_shop_id'] !== '') {
            $taskData['app_shop_id'] = (string) $taskData['app_shop_id'];
        }

        $response['data'] = $taskData;

        return $response;
    }

    private function resolveMenuTaskProgressState(array $taskData): string
    {
        if (empty($taskData)) {
            return 'submitted';
        }

        $status = isset($taskData['status']) ? (int) $taskData['status'] : null;
        $message = strtolower(trim((string) ($taskData['message'] ?? '')));
        $failureMessage = strtolower(trim((string) ($this->extractMenuTaskFailureMessage($taskData) ?? '')));

        if ($status === 2 || $failureMessage !== '' || str_contains($message, 'fail') || str_contains($message, 'error')) {
            return 'failed';
        }

        if (
            $status === 1
            || str_contains($message, 'success')
            || str_contains($message, 'complete')
            || str_contains($message, 'done')
        ) {
            return 'completed';
        }

        if ($message === 'waiting' || str_contains($message, 'wait') || $status === 0) {
            return 'processing';
        }

        if (str_contains($message, 'process') || str_contains($message, 'running')) {
            return 'processing';
        }

        return 'completed';
    }

    public function persistProviderMenuTaskState(People $provider, array $response, int|string|null $fallbackTaskId = null): string
    {
        $taskData = is_array($response['data'] ?? null) ? $response['data'] : [];
        $taskId = $taskData['taskID'] ?? $taskData['taskId'] ?? $fallbackTaskId;
        $taskStatus = isset($taskData['status']) ? (string) $taskData['status'] : '';
        $taskMessage = trim((string) ($this->extractMenuTaskFailureMessage($taskData) ?: ($taskData['message'] ?? $response['errmsg'] ?? '')));
        $publishState = $this->resolveMenuTaskProgressState($taskData);

        $this->persistProviderIntegrationState($provider, [
            'last_menu_task_id' => $taskId,
            'last_menu_task_status' => $taskStatus,
            'last_menu_task_message' => $taskMessage,
            'last_menu_task_checked_at' => date('Y-m-d H:i:s'),
            'last_menu_publish_state' => $publishState,
        ]);

        if ($publishState === 'failed') {
            $this->persistProviderLastError(
                $provider,
                $taskStatus !== '' ? 'menu_task:' . $taskStatus : 'menu_task:failed',
                $taskMessage !== '' ? $taskMessage : 'A publicacao do cardapio falhou na 99Food.'
            );
        }

        return $publishState;
    }

    private function markProviderMenuPublished(People $provider, ?string $message = null): void
    {
        $this->persistProviderIntegrationState($provider, [
            'last_menu_publish_state' => 'published',
            'last_menu_task_checked_at' => date('Y-m-d H:i:s'),
            'last_menu_task_message' => $message ?: 'Cardapio publicado com sucesso no catalogo remoto.',
            'last_error_code' => '',
            'last_error_message' => '',
        ]);
    }

    private function markProviderMenuSyncError(People $provider, ?string $message = null): void
    {
        $syncMessage = trim((string) ($message ?? ''));

        $this->persistProviderIntegrationState($provider, [
            'last_menu_publish_state' => 'sync_error',
            'last_menu_task_checked_at' => date('Y-m-d H:i:s'),
            'last_menu_task_message' => $syncMessage !== '' ? $syncMessage : 'A task concluiu, mas nao foi possivel confirmar o cardapio remoto.',
        ]);
    }

    private function persistProviderDeliveryAreaState(People $provider, array $deliveryAreaData): void
    {
        $areaGroups = is_array($deliveryAreaData['area_group'] ?? null) ? $deliveryAreaData['area_group'] : [];

        $this->persistProviderIntegrationState($provider, [
            'delivery_area_count' => count($areaGroups),
            'last_sync_at' => date('Y-m-d H:i:s'),
            'remote_connected' => 1,
            'last_error_code' => '',
            'last_error_message' => '',
        ]);
    }

    private function syncStoreStateFromResponse(People $provider, ?array $response): ?array
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : null;
        $errno = isset($response['errno']) ? (int) $response['errno'] : null;

        if ($errno === 0 && is_array($data)) {
            $this->persistProviderStoreState($provider, $data);
            return $response;
        }

        $this->persistProviderLastError($provider, $response['errno'] ?? null, $response['errmsg'] ?? null);

        return $response;
    }

    private function syncMenuStateFromResponse(People $provider, ?array $response, mixed $taskId = null): ?array
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : null;
        $errno = isset($response['errno']) ? (int) $response['errno'] : null;

        if ($errno === 0 && is_array($data)) {
            $this->persistProviderMenuState($provider, $data, $taskId);
            $this->syncPublishedProductsForProvider($provider, $this->resolvePublishedRemoteItemIds([
                'data' => $data,
            ]));
            return $response;
        }

        $this->persistProviderLastError($provider, $response['errno'] ?? null, $response['errmsg'] ?? null);

        return $response;
    }

    private function syncDeliveryAreaStateFromResponse(People $provider, ?array $response): ?array
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : null;
        $errno = isset($response['errno']) ? (int) $response['errno'] : null;

        if ($errno === 0 && is_array($data)) {
            $this->persistProviderDeliveryAreaState($provider, $data);
            return $response;
        }

        $this->persistProviderLastError($provider, $response['errno'] ?? null, $response['errmsg'] ?? null);

        return $response;
    }

    public function syncStoreStatusWebhook(array $json): void
    {
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $candidateShopIds = array_values(array_unique(array_filter([
            $this->normalizeIncomingFood99Value($json['app_shop_id'] ?? null),
            $this->normalizeIncomingFood99Value($data['shop_id'] ?? null),
        ], static fn(string $value): bool => $value !== '')));

        if ($candidateShopIds === []) {
            self::$logger->warning('Food99 shopStatus webhook ignored because no shop identifier was provided', [
                'payload' => $json,
            ]);
            return;
        }

        $provider = null;
        foreach ($candidateShopIds as $candidateShopId) {
            $provider = $this->findFood99EntityByExtraData('People', 'code', $candidateShopId, People::class);
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
            self::$logger->warning('Food99 shopStatus webhook ignored because provider was not found', [
                'candidate_shop_ids' => $candidateShopIds,
            ]);
            return;
        }

        $previousState = $this->getStoredIntegrationState($provider);
        $previousOnline = (bool) ($previousState['online'] ?? false);
        $currentOnline = $this->resolveFood99WebhookOnlineState($data);

        $this->persistProviderStoreState($provider, $data);

        if ($currentOnline === null) {
            return;
        }

        if ($previousOnline === $currentOnline) {
            return;
        }

        $providerName = trim((string) ($provider->getName() ?? ''));
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
            'status' => $currentOnline ? 'open' : 'closed',
            'realStatus' => $currentOnline ? 'open' : 'closed',
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

    public function resolveFood99WebhookOnlineState(array $data): ?bool
    {
        if (array_key_exists('biz_status', $data) && is_numeric($data['biz_status'])) {
            return (int) $data['biz_status'] === 1;
        }

        if (array_key_exists('online', $data)) {
            return filter_var($data['online'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        }

        return null;
    }

    public function listProvidersWithFood99Binding(int $limit = 100): array
    {
        $this->init();

        $safeLimit = max(1, min($limit, 1000));
        $sql = <<<SQL
            SELECT DISTINCT ed.entity_id
            FROM extra_data ed
            INNER JOIN extra_fields ef ON ef.id = ed.extra_fields_id
            WHERE ef.context = :context
              AND LOWER(ed.entity_name) = 'people'
              AND ef.field_name = 'code'
              AND ed.data_value <> ''
            ORDER BY ed.entity_id ASC
            LIMIT {$safeLimit}
        SQL;

        $rows = $this->entityManager->getConnection()->fetchFirstColumn($sql, [
            'context' => self::APP_CONTEXT,
        ]);

        $providers = [];
        foreach ($rows as $row) {
            if (!is_numeric($row)) {
                continue;
            }

            $provider = $this->entityManager->getRepository(People::class)->find((int) $row);
            if ($provider instanceof People) {
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    public function reconcileProviderState(People $provider, string $source = 'manual'): array
    {
        $this->init();

        $startedAt = microtime(true);
        $sync = $this->syncIntegrationState($provider);
        $errors = is_array($sync['errors'] ?? null) ? $sync['errors'] : [];
        $status = empty($errors) ? 'ok' : 'partial_error';
        $message = empty($errors)
            ? 'Reconciliacao concluida com sucesso.'
            : 'Reconciliacao concluida com inconsistencias em: ' . implode(', ', array_keys($errors));

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $this->persistProviderIntegrationState($provider, [
            'last_reconcile_at' => date('Y-m-d H:i:s'),
            'last_reconcile_status' => $status,
            'last_reconcile_message' => $message,
            'last_reconcile_source' => $source,
            'last_reconcile_duration_ms' => $durationMs,
        ]);

        return [
            'provider_id' => $provider->getId(),
            'status' => $status,
            'message' => $message,
            'duration_ms' => $durationMs,
            'errors' => $errors,
            'sync' => $sync,
        ];
    }

    public function getStoredOperationalSettings(People $provider): array
    {
        $this->init();

        $providerId = (int) $provider->getId();
        $normalize = static fn(mixed $value): ?string => (trim((string) $value) === '' ? null : trim((string) $value));
        $otherInformations = $this->getDecodedEntityOtherInformations($provider);
        $context = $this->decodeEntityOtherInformationsValue($otherInformations[self::APP_CONTEXT] ?? null);

        return [
            'delivery_radius' => $normalize($context['store_delivery_radius'] ?? $this->getFood99ExtraDataValue('People', $providerId, 'store_delivery_radius')),
            'open_time' => $normalize($context['store_open_time'] ?? $this->getFood99ExtraDataValue('People', $providerId, 'store_open_time')),
            'close_time' => $normalize($context['store_close_time'] ?? $this->getFood99ExtraDataValue('People', $providerId, 'store_close_time')),
            'delivery_method' => $normalize($context['store_delivery_method'] ?? $this->getFood99ExtraDataValue('People', $providerId, 'store_delivery_method')),
            'confirm_method' => $normalize($context['store_confirm_method'] ?? $this->getFood99ExtraDataValue('People', $providerId, 'store_confirm_method')),
            'delivery_area_id' => $normalize($context['store_delivery_area_id'] ?? $this->getFood99ExtraDataValue('People', $providerId, 'store_delivery_area_id')),
            'settlement_wallet_id' => $normalize($context['store_settlement_wallet_id'] ?? $this->getFood99ExtraDataValue('People', $providerId, 'store_settlement_wallet_id')),
            'settings_synced_at' => $normalize($context['store_settings_synced_at'] ?? $this->getFood99ExtraDataValue('People', $providerId, 'store_settings_synced_at')),
        ];
    }

    public function persistOperationalSettings(People $provider, array $settings, bool $allowEmpty = false): void
    {
        $this->init();

        $fieldMap = [
            'delivery_radius' => 'store_delivery_radius',
            'open_time' => 'store_open_time',
            'close_time' => 'store_close_time',
            'delivery_method' => 'store_delivery_method',
            'confirm_method' => 'store_confirm_method',
            'delivery_area_id' => 'store_delivery_area_id',
            'settlement_wallet_id' => 'store_settlement_wallet_id',
        ];

        $currentSettings = $this->getStoredOperationalSettings($provider);
        $stateFields = [];
        $persisted = false;
        foreach ($fieldMap as $settingKey => $fieldName) {
            if (!array_key_exists($settingKey, $settings)) {
                continue;
            }

            $normalized = trim((string) $settings[$settingKey]);
            if ($normalized === '' && !$allowEmpty) {
                continue;
            }

            $existing = trim((string) ($currentSettings[$settingKey] ?? ''));
            if ($existing === $normalized) {
                continue;
            }

            $stateFields[$fieldName] = $normalized;
            $persisted = true;
        }

        if ($persisted) {
            $stateFields['store_settings_synced_at'] = date('Y-m-d H:i:s');
            $this->persistProviderIntegrationState($provider, $stateFields);
        }
    }

    public function resolveFood99SettlementWallet(People $provider, mixed $walletId): ?Wallet
    {
        $this->init();

        $normalizedWalletId = trim((string) $walletId);
        if ($normalizedWalletId === '' || !ctype_digit($normalizedWalletId)) {
            return null;
        }

        $wallet = $this->entityManager->getRepository(Wallet::class)->find((int) $normalizedWalletId);
        if (!$wallet instanceof Wallet) {
            return null;
        }

        $walletPeople = $wallet->getPeople();
        if (!$walletPeople instanceof People) {
            return null;
        }

        if ((int) $walletPeople->getId() !== (int) $provider->getId()) {
            return null;
        }

        return $wallet;
    }

    public function getStoredSettlementWallet(People $provider): ?Wallet
    {
        $settings = $this->getStoredOperationalSettings($provider);

        return $this->resolveFood99SettlementWallet($provider, $settings['settlement_wallet_id'] ?? null);
    }

    public function getLatestProviderOrderDeliveryType(People $provider): ?string
    {
        $this->init();

        $providerId = (int) $provider->getId();
        if ($providerId <= 0) {
            return null;
        }

        $order = $this->entityManager->getRepository(Order::class)
            ->findLatestMarketplaceOrderForProvider($providerId, self::APP_CONTEXT);

        if (!$order instanceof Order) {
            return null;
        }

        $otherInformations = $this->getDecodedEntityOtherInformations($order);
        $context = $this->decodeEntityOtherInformationsValue($otherInformations[self::APP_CONTEXT] ?? null);
        $normalized = trim((string) ($context['delivery_type'] ?? ''));
        if (!in_array($normalized, ['1', '2'], true)) {
            return null;
        }

        return $normalized;
    }

    public function getStoredIntegrationState(People $provider): array
    {
        $this->init();

        $otherInformations = $this->getDecodedEntityOtherInformations($provider);
        $context = $this->decodeEntityOtherInformationsValue($otherInformations[self::APP_CONTEXT] ?? null);
        $bizStatus = $context['biz_status'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'biz_status');
        $subBizStatus = $context['sub_biz_status'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'sub_biz_status');
        $storeStatus = $context['store_status'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'store_status');
        $food99Code = $this->resolveMarketplaceProviderCode($provider, self::APP_CONTEXT);
        $menuCount = $context['menu_count'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'menu_count');
        $menuItemCount = $context['menu_item_count'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'menu_item_count');
        $deliveryAreaCount = $context['delivery_area_count'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'delivery_area_count');
        $remoteOnlyItemCount = $context['remote_only_item_count'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'remote_only_item_count');

        return [
            'connected' => !empty($food99Code),
            'food99_code' => $food99Code,
            'app_shop_id' => (string) $provider->getId(),
            'biz_status' => is_numeric($bizStatus) ? (int) $bizStatus : null,
            'sub_biz_status' => is_numeric($subBizStatus) ? (int) $subBizStatus : null,
            'store_status' => is_numeric($storeStatus) ? (int) $storeStatus : null,
            'remote_connected' => ($context['remote_connected'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'remote_connected')) === '1',
            'online' => ($context['online'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'online')) === '1',
            'last_sync_at' => $context['last_sync_at'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_sync_at'),
            'last_menu_task_id' => $context['last_menu_task_id'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_menu_task_id'),
            'last_menu_task_status' => $context['last_menu_task_status'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_menu_task_status'),
            'last_menu_task_message' => $context['last_menu_task_message'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_menu_task_message'),
            'last_menu_task_checked_at' => $context['last_menu_task_checked_at'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_menu_task_checked_at'),
            'last_menu_publish_state' => $context['last_menu_publish_state'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_menu_publish_state'),
            'menu_count' => is_numeric($menuCount) ? (int) $menuCount : 0,
            'menu_item_count' => is_numeric($menuItemCount) ? (int) $menuItemCount : 0,
            'delivery_area_count' => is_numeric($deliveryAreaCount) ? (int) $deliveryAreaCount : 0,
            'remote_only_item_count' => is_numeric($remoteOnlyItemCount) ? (int) $remoteOnlyItemCount : 0,
            'last_error_code' => $context['last_error_code'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_error_code'),
            'last_error_message' => $context['last_error_message'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_error_message'),
            'last_webhook_event_id' => $context['last_webhook_event_id'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_webhook_event_id'),
            'last_webhook_event_type' => $context['last_webhook_event_type'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_webhook_event_type'),
            'last_webhook_event_at' => $context['last_webhook_event_at'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_webhook_event_at'),
            'last_webhook_received_at' => $context['last_webhook_received_at'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_webhook_received_at'),
            'last_webhook_processed_at' => $context['last_webhook_processed_at'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_webhook_processed_at'),
            'last_webhook_order_id' => $context['last_webhook_order_id'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_webhook_order_id'),
            'last_webhook_shop_id' => $context['last_webhook_shop_id'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_webhook_shop_id'),
            'last_reconcile_at' => $context['last_reconcile_at'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_reconcile_at'),
            'last_reconcile_status' => $context['last_reconcile_status'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_reconcile_status'),
            'last_reconcile_message' => $context['last_reconcile_message'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_reconcile_message'),
            'last_reconcile_source' => $context['last_reconcile_source'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_reconcile_source'),
            'last_reconcile_duration_ms' => $context['last_reconcile_duration_ms'] ?? $this->getFood99ExtraDataValue('People', (int) $provider->getId(), 'last_reconcile_duration_ms'),
        ];
    }

    private function getStoredOrderIntegrationState(Order $order): array
    {
        $otherInformations = $this->getDecodedEntityOtherInformations($order);
        $context = $this->decodeEntityOtherInformationsValue($otherInformations[self::APP_CONTEXT] ?? null);

        $deliveryType = $this->normalizeIncomingFood99Value($context['delivery_type'] ?? null);
        $deliveryLabel = $this->normalizeIncomingFood99Value($context['delivery_label'] ?? null);

        $isStoreDelivery = (bool) ($context['is_store_delivery'] ?? false);
        if (!$isStoreDelivery) {
            $isStoreDelivery = in_array($deliveryType, ['2', 'store_delivery', 'self_delivery'], true);
        }

        $isPlatformDelivery = (bool) ($context['is_platform_delivery'] ?? false);
        if (!$isPlatformDelivery) {
            $isPlatformDelivery = in_array($deliveryType, ['1', 'platform_delivery'], true);
        }

        if ($deliveryLabel === '') {
            $deliveryLabel = $isStoreDelivery
                ? 'Entrega da loja'
                : ($isPlatformDelivery ? 'Entrega 99Food' : 'Indefinido');
        }

        return [
            'delivery_type' => $deliveryType,
            'delivery_label' => $deliveryLabel,
            'is_store_delivery' => $isStoreDelivery,
            'is_platform_delivery' => $isPlatformDelivery,
        ];
    }

    private function isCancelReasonApplicableToState(array $reason, array $state): bool
    {
        $scope = strtolower(trim((string) ($reason['applicable_to'] ?? 'all')));
        if ($scope === 'all') {
            return true;
        }

        if ($scope === 'self_delivery') {
            return !empty($state['is_store_delivery']);
        }

        return true;
    }

    private function buildShopCancelReasonListForState(array $state): array
    {
        return array_map(function (array $reason) use ($state) {
            $isApplicable = $this->isCancelReasonApplicableToState($reason, $state);

            return [
                'reason_id' => (int) $reason['reason_id'],
                'description' => (string) $reason['description'],
                'applicable_to' => (string) $reason['applicable_to'],
                'requires_description' => (int) $reason['reason_id'] === 1080,
                'applicable' => $isApplicable,
            ];
        }, self::SHOP_CANCEL_REASONS);
    }

    public function getOrderCancelReasons(Order $order): array
    {
        $state = $this->getStoredOrderIntegrationState($order);

        return [
            'errno' => 0,
            'errmsg' => 'ok',
            'data' => [
                'delivery_type' => $state['delivery_type'] ?? '',
                'delivery_label' => $state['delivery_label'] ?? 'Indefinido',
                'reasons' => $this->buildShopCancelReasonListForState($state),
            ],
        ];
    }

    public function quoteDelivery(Order $order): array
    {
        $this->init();

        $provider = $order->getProvider();
        if (!$provider instanceof People) {
            return $this->persistFood99QuoteFailure($order, 'unavailable', 'Pedido sem provider vinculado.');
        }

        $sourceOrder = $this->resolveFood99QuoteSourceOrder($order);
        $stored = $this->getStoredIntegrationState($provider);
        if (empty($stored['connected'])) {
            return $this->persistFood99QuoteFailure($order, 'unavailable', '99 Food nao esta conectada.');
        }

        $pickupAddress = $this->resolveFood99QuotePickupAddress($order, $sourceOrder);
        if (!$pickupAddress instanceof Address) {
            return $this->persistFood99QuoteFailure($order, 'unavailable', 'Pedido sem endereco de coleta valido.');
        }

        $dropoffAddress = $this->resolveFood99QuoteDropoffAddress($order, $sourceOrder);
        if (!$dropoffAddress instanceof Address) {
            return $this->persistFood99QuoteFailure($order, 'unavailable', 'Pedido sem endereco de entrega valido.');
        }

        $deliveryAreasResponse = $this->listDeliveryAreas($provider);
        if (!is_array($deliveryAreasResponse) || !$this->isSuccessfulErrno($deliveryAreasResponse['errno'] ?? null)) {
            $message = $this->normalizeIncomingFood99Value($deliveryAreasResponse['errmsg'] ?? null);
            if ($message === '') {
                $message = 'Nao foi possivel consultar as areas de entrega da 99 Food.';
            }

            return $this->persistFood99QuoteFailure($order, 'error', $message, [
                'provider_key' => 'food99',
                'provider_label' => '99 Food',
                'quote_requested_at' => date('Y-m-d H:i:s'),
                'quote_updated_at' => date('Y-m-d H:i:s'),
                'pickup_address_id' => $pickupAddress->getId(),
                'dropoff_address_id' => $dropoffAddress->getId(),
                'main_order_id' => $order->getMainOrderId(),
                'source_order_id' => $sourceOrder->getId(),
                'quote_response' => $deliveryAreasResponse,
            ]);
        }

        $deliveryAreaMatch = $this->resolveFood99QuoteDeliveryAreaMatch($deliveryAreasResponse, $dropoffAddress);
        if (!$deliveryAreaMatch) {
            return $this->persistFood99QuoteFailure($order, 'unavailable', 'Endereco de entrega fora da area de cobertura da 99 Food.', [
                'provider_key' => 'food99',
                'provider_label' => '99 Food',
                'quote_requested_at' => date('Y-m-d H:i:s'),
                'quote_updated_at' => date('Y-m-d H:i:s'),
                'pickup_address_id' => $pickupAddress->getId(),
                'dropoff_address_id' => $dropoffAddress->getId(),
                'main_order_id' => $order->getMainOrderId(),
                'source_order_id' => $sourceOrder->getId(),
                'quote_response' => $deliveryAreasResponse['data'] ?? $deliveryAreasResponse,
            ]);
        }

        $now = date('Y-m-d H:i:s');
        $price = $deliveryAreaMatch['price'];
        $eta = $deliveryAreaMatch['eta'];
        $deliveryAreaId = $deliveryAreaMatch['delivery_area_id'];
        $deliveryAreaLabel = $deliveryAreaMatch['delivery_area_label'];
        $matchedArea = $deliveryAreaMatch['area'];
        $quoteResponse = [
            'delivery_areas' => $deliveryAreasResponse['data'] ?? $deliveryAreasResponse,
            'matched_delivery_area' => $matchedArea,
            'matched_delivery_area_id' => $deliveryAreaId,
            'matched_delivery_area_label' => $deliveryAreaLabel,
            'price' => $price,
            'eta' => $eta,
        ];

        $storedState = array_merge($this->getStoredFood99QuoteState($order), [
            'provider_key' => 'food99',
            'provider_label' => '99 Food',
            'quote_state' => 'ready',
            'quote_message' => 'Cotacao pronta',
            'quote_requested_at' => $now,
            'quote_updated_at' => $now,
            'price' => $price,
            'eta' => $eta,
            'tracking_url' => null,
            'remote_order_id' => null,
            'pickup_address_id' => $pickupAddress->getId(),
            'dropoff_address_id' => $dropoffAddress->getId(),
            'main_order_id' => $order->getMainOrderId(),
            'source_order_id' => $sourceOrder->getId(),
            'delivery_area_id' => $deliveryAreaId,
            'delivery_area_label' => $deliveryAreaLabel,
            'delivery_area_match' => $matchedArea,
            'quote_response' => $quoteResponse,
        ]);
        $this->persistFood99QuoteState($order, $storedState, [
            'flow' => 'quote',
            'provider_key' => 'food99',
            'provider_label' => '99 Food',
            'quote_state' => 'ready',
            'quote_message' => 'Cotacao pronta',
            'quote_requested_at' => $now,
            'quote_updated_at' => $now,
            'price' => $price,
            'eta' => $eta,
            'tracking_url' => null,
            'quote_response' => $quoteResponse,
            'pickup_address_id' => $pickupAddress->getId(),
            'dropoff_address_id' => $dropoffAddress->getId(),
            'main_order_id' => $order->getMainOrderId(),
            'source_order_id' => $sourceOrder->getId(),
            'delivery_area_id' => $deliveryAreaId,
            'delivery_area_label' => $deliveryAreaLabel,
        ]);

        return [
            'errno' => 0,
            'errmsg' => 'ok',
            'data' => [
                'order_id' => $order->getId(),
                'quote_state' => 'ready',
                'quote_message' => 'Cotacao pronta',
                'price' => $price,
                'eta' => $eta,
                'delivery_area_id' => $deliveryAreaId,
                'delivery_area_label' => $deliveryAreaLabel,
                'quote_response' => $quoteResponse,
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

        $state = $this->getStoredFood99QuoteState($order);
        if ($this->normalizeIncomingFood99Value($state['remote_order_id'] ?? null) !== '') {
            return [
                'errno' => 0,
                'errmsg' => 'ok',
                'already_requested' => true,
                'data' => [
                    'order_id' => $order->getId(),
                    'remote_order_id' => $state['remote_order_id'] ?? null,
                    'tracking_url' => $state['tracking_url'] ?? null,
                ],
            ];
        }

        $quoteState = strtolower(trim((string) ($state['quote_state'] ?? '')));
        if (!in_array($quoteState, ['ready', 'selected', 'requested'], true)) {
            return [
                'errno' => 422,
                'errmsg' => 'Cotacao 99 Food ainda nao esta pronta.',
            ];
        }

        return [
            'errno' => 0,
            'errmsg' => 'ok',
            'data' => [
                'order_id' => $order->getId(),
                'remote_order_id' => $state['remote_order_id'] ?? null,
                'tracking_url' => $state['tracking_url'] ?? null,
                'quote_state' => 'selected',
                'quote_price' => isset($state['price']) ? (float) $state['price'] : null,
                'quote_eta' => $state['eta'] ?? null,
            ],
        ];
    }

    public function resolveFood99QuoteDeliveryAreaMatch(array $deliveryAreasResponse, Address $dropoffAddress): ?array
    {
        $latitude = $this->normalizeFood99CoordinateValue($dropoffAddress->getLatitude());
        $longitude = $this->normalizeFood99CoordinateValue($dropoffAddress->getLongitude());
        if ($latitude === null || $longitude === null) {
            return null;
        }

        $deliveryAreaGroups = $this->extractFood99DeliveryAreaGroups($deliveryAreasResponse);
        $matches = [];

        foreach ($deliveryAreaGroups as $index => $deliveryAreaGroup) {
            if (!is_array($deliveryAreaGroup)) {
                continue;
            }

            $polygon = $this->normalizeFood99DeliveryAreaPolygon($deliveryAreaGroup['points'] ?? null);
            if ($polygon === []) {
                continue;
            }

            if (!$this->isFood99PointInsidePolygon($latitude, $longitude, $polygon)) {
                continue;
            }

            $matches[] = [
                'index' => (int) $index,
                'area' => $deliveryAreaGroup,
                'polygon_area' => abs($this->calculateFood99PolygonArea($polygon)),
                'delivery_area_id' => $this->resolveFood99DeliveryAreaValue($deliveryAreaGroup, 'id', 'delivery_area_id', 'area_id', 'deliveryAreaId'),
                'delivery_area_label' => $this->resolveFood99DeliveryAreaValue($deliveryAreaGroup, 'name', 'title', 'description', 'area_name', 'delivery_area_name'),
                'price' => $this->resolveFood99DeliveryAreaPrice($deliveryAreaGroup),
                'eta' => $this->formatFood99QuoteEta($this->resolveFood99DeliveryAreaEtaSeconds($deliveryAreaGroup)),
            ];
        }

        if ($matches === []) {
            return null;
        }

        usort($matches, static function (array $left, array $right): int {
            $areaComparison = ($left['polygon_area'] ?? 0) <=> ($right['polygon_area'] ?? 0);
            if ($areaComparison !== 0) {
                return $areaComparison;
            }

            $priceComparison = ($left['price'] ?? 0) <=> ($right['price'] ?? 0);
            if ($priceComparison !== 0) {
                return $priceComparison;
            }

            return ($left['index'] ?? 0) <=> ($right['index'] ?? 0);
        });

        $match = $matches[0];

        return [
            'delivery_area_id' => $match['delivery_area_id'] ?? null,
            'delivery_area_label' => $match['delivery_area_label'] ?? null,
            'price' => isset($match['price']) ? (float) $match['price'] : null,
            'eta' => $match['eta'] ?? null,
            'area' => $match['area'] ?? [],
        ];
    }

    private function extractFood99DeliveryAreaGroups(array $deliveryAreasResponse): array
    {
        $data = is_array($deliveryAreasResponse['data'] ?? null) ? $deliveryAreasResponse['data'] : [];
        $deliveryAreaGroups = $data['area_group'] ?? $deliveryAreasResponse['area_group'] ?? [];

        if (is_string($deliveryAreaGroups)) {
            $decoded = json_decode($deliveryAreaGroups, true);
            $deliveryAreaGroups = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($deliveryAreaGroups)) {
            return [];
        }

        return array_values(array_filter($deliveryAreaGroups, static fn(mixed $deliveryAreaGroup): bool => is_array($deliveryAreaGroup)));
    }

    private function normalizeFood99DeliveryAreaPolygon(mixed $points): array
    {
        if (is_string($points)) {
            $decoded = json_decode($points, true);
            $points = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($points)) {
            return [];
        }

        $polygon = [];
        foreach ($points as $point) {
            $normalizedPoint = $this->normalizeFood99DeliveryAreaPoint($point);
            if ($normalizedPoint !== null) {
                $polygon[] = $normalizedPoint;
            }
        }

        return $polygon;
    }

    private function normalizeFood99DeliveryAreaPoint(mixed $point): ?array
    {
        if (is_object($point)) {
            $point = json_decode(json_encode($point, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
        }

        if (is_string($point)) {
            $decoded = json_decode($point, true);
            $point = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($point)) {
            return null;
        }

        $latitude = $this->normalizeFood99CoordinateValue($point['latitude'] ?? $point['lat'] ?? $point['y'] ?? $point['point_lat'] ?? $point['pointLatitude'] ?? null);
        $longitude = $this->normalizeFood99CoordinateValue($point['longitude'] ?? $point['lng'] ?? $point['lon'] ?? $point['x'] ?? $point['point_lng'] ?? $point['pointLongitude'] ?? null);

        if ($latitude === null || $longitude === null) {
            $values = array_values(array_filter(array_map(
                [$this, 'normalizeFood99CoordinateValue'],
                $point
            ), static fn(mixed $value): bool => $value !== null));

            if (count($values) >= 2) {
                $first = (float) $values[0];
                $second = (float) $values[1];

                if (abs($first) <= 90 && abs($second) <= 180) {
                    $latitude = $first;
                    $longitude = $second;
                } elseif (abs($second) <= 90 && abs($first) <= 180) {
                    $latitude = $second;
                    $longitude = $first;
                }
            }
        }

        if ($latitude === null || $longitude === null) {
            return null;
        }

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
    }

    private function normalizeFood99CoordinateValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function isFood99PointInsidePolygon(float $latitude, float $longitude, array $polygon): bool
    {
        $count = count($polygon);
        if ($count < 3) {
            return false;
        }

        $inside = false;
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $yi = (float) ($polygon[$i]['latitude'] ?? 0);
            $xi = (float) ($polygon[$i]['longitude'] ?? 0);
            $yj = (float) ($polygon[$j]['latitude'] ?? 0);
            $xj = (float) ($polygon[$j]['longitude'] ?? 0);

            $intersects = (($yi > $latitude) !== ($yj > $latitude))
                && ($longitude < (($xj - $xi) * ($latitude - $yi) / (($yj - $yi) ?: 1.0)) + $xi);

            if ($intersects) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    private function calculateFood99PolygonArea(array $polygon): float
    {
        $count = count($polygon);
        if ($count < 3) {
            return 0.0;
        }

        $sum = 0.0;
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (float) ($polygon[$i]['longitude'] ?? 0);
            $yi = (float) ($polygon[$i]['latitude'] ?? 0);
            $xj = (float) ($polygon[$j]['longitude'] ?? 0);
            $yj = (float) ($polygon[$j]['latitude'] ?? 0);

            $sum += ($xj * $yi) - ($xi * $yj);
        }

        return $sum / 2.0;
    }

    private function resolveFood99DeliveryAreaValue(array $deliveryAreaGroup, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $deliveryAreaGroup)) {
                continue;
            }

            $value = $this->normalizeIncomingFood99Value($deliveryAreaGroup[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function resolveFood99DeliveryAreaPrice(array $deliveryAreaGroup): ?float
    {
        foreach (['price', 'delivery_price', 'deliveryPrice', 'fee', 'delivery_fee'] as $key) {
            if (!array_key_exists($key, $deliveryAreaGroup)) {
                continue;
            }

            $value = $deliveryAreaGroup[$key];
            if ($value === null || $value === '') {
                continue;
            }

            return $this->normalizeFood99Money($value);
        }

        return null;
    }

    private function resolveFood99DeliveryAreaEtaSeconds(array $deliveryAreaGroup): ?int
    {
        foreach (['avg_delivery_eta', 'delivery_eta', 'eta'] as $key) {
            if (!array_key_exists($key, $deliveryAreaGroup)) {
                continue;
            }

            $value = $deliveryAreaGroup[$key];
            if ($value === null || $value === '') {
                continue;
            }

            if (!is_numeric($value)) {
                continue;
            }

            return max(0, (int) round((float) $value));
        }

        return null;
    }

    private function formatFood99QuoteEta(?int $etaSeconds): ?string
    {
        if ($etaSeconds === null || $etaSeconds <= 0) {
            return null;
        }

        $minutes = max(1, (int) ceil($etaSeconds / 60));

        if ($minutes >= 60) {
            $hours = intdiv($minutes, 60);
            $remainingMinutes = $minutes % 60;

            if ($remainingMinutes === 0) {
                return sprintf('%dh', $hours);
            }

            return sprintf('%dh %d min', $hours, $remainingMinutes);
        }

        return sprintf('%d min', $minutes);
    }

    public function getAuthorizationPage(array $payload): ?array
    {
        $this->init();

        return $this->call99AppEndpointWithResponse('POST', '/shop_center/v1/authorize/get_url', $payload);
    }

    public function bindStore(array $payload): ?array
    {
        $this->init();

        return $this->call99AppEndpointWithResponse('POST', '/shop_center/v1/authorize/bind', $payload);
    }

    public function listAuthorizedStores(array $payload = []): ?array
    {
        $this->init();

        return $this->call99AppEndpointWithResponse('GET', '/shop_center/v1/authorize/list', $payload);
    }

    public function listBindStores(array $payload = []): ?array
    {
        $this->init();

        return $this->call99AppEndpointWithResponse('GET', '/shop_center/v1/shop/list', $payload);
    }

    public function unbindStore(People $provider, array $payload = []): ?array
    {
        $this->init();

        return $this->call99AppEndpointWithResponse('POST', '/shop_center/v1/authorize/unbind', $payload);
    }

    public function setStoreOrderConfirmationMethod(People $provider, array $payload): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('POST', '/v1/shop/shop/setconfirmmethod', $payload, $provider);
    }

    public function getStoreOrderConfirmationMethod(People $provider): ?array
    {
        $this->init();

        $postResponse = $this->call99StoreEndpointWithResponse('POST', '/v1/shop/shop/getconfirmmethod', [], $provider);
        if ($this->isSuccessfulErrno($postResponse['errno'] ?? null)) {
            return $postResponse;
        }

        $getResponse = $this->call99StoreEndpointWithResponse('GET', '/v1/shop/shop/getconfirmmethod', [], $provider);
        if ($this->isSuccessfulErrno($getResponse['errno'] ?? null)) {
            return $getResponse;
        }

        return $postResponse ?: $getResponse;
    }

    public function getStoreDetails(People $provider): ?array
    {
        $this->init();

        return $this->syncStoreStateFromResponse(
            $provider,
            $this->call99StoreEndpointWithResponse('GET', '/v1/shop/shop/detail', [], $provider)
        );
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

        $response = $this->call99StoreEndpointWithResponse('POST', '/v1/shop/shop/setStatus', $payload, $provider);
        if ($this->isSuccessfulErrno($response['errno'] ?? null)) {
            $this->persistProviderIntegrationState($provider, [
                'biz_status' => $bizStatus,
                'online' => $bizStatus === 1 ? 1 : 0,
                'remote_connected' => 1,
                'last_sync_at' => date('Y-m-d H:i:s'),
                'last_error_code' => '',
                'last_error_message' => '',
            ]);
        } else {
            $this->persistProviderLastError($provider, $response['errno'] ?? null, $response['errmsg'] ?? null);
        }

        return $response;
    }

    public function setStoreCancellationRefund(People $provider, array $payload): ?array
    {
        $this->init();

        return $this->call99StoreEndpointWithResponse('POST', '/v1/shop/apply/set', $payload, $provider);
    }

    public function markProviderConnected(People $provider, ?string $shopId = null): void
    {
        $this->init();

        $normalizedShopId = $this->normalizeIncomingFood99Value($shopId);
        $fields = [
            'remote_connected' => 1,
            'last_sync_at' => date('Y-m-d H:i:s'),
            'last_error_code' => '',
            'last_error_message' => '',
        ];

        if ($normalizedShopId !== '') {
            $fields['code'] = $normalizedShopId;
        }

        $this->persistProviderIntegrationState($provider, $fields);
    }

    public function clearProviderBindingState(People $provider): void
    {
        $this->init();

        $this->persistProviderIntegrationState($provider, [
            'code' => null,
            'remote_connected' => 0,
            'online' => 0,
            'biz_status' => null,
            'sub_biz_status' => null,
            'store_status' => null,
            'last_sync_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function getStoreMenuDetails(People $provider): ?array
    {
        $this->init();

        return $this->syncMenuStateFromResponse(
            $provider,
            $this->call99StoreEndpointWithResponse('GET', '/v3/item/item/list', [], $provider)
        );
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

        return $this->call99StoreMultipartEndpointWithResponse('POST', '/v3/image/image/uploadImage', $payload, $provider);
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

        return $this->syncDeliveryAreaStateFromResponse(
            $provider,
            $this->call99StoreEndpointWithResponse('GET', '/v1/shop/deliveryArea/list', [], $provider)
        );
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

}

