<?php

namespace ControleOnline\Controller\iFood;

use ControleOnline\Entity\Order;
use ControleOnline\Entity\People;
use ControleOnline\Service\iFoodService;
use ControleOnline\Service\OrderActionService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface as Security;
use Symfony\Component\Security\Http\Attribute\Security as SecurityAttribute;

#[SecurityAttribute("is_granted('ROLE_ADMIN') or is_granted('ROLE_CLIENT')")]
class IntegrationController extends AbstractController
{
    private const IFOOD_SELF_DELIVERY_CONFIRMATION_URL = 'https://confirmacao-entrega-propria.ifood.com.br/';

    public function __construct(
        private EntityManagerInterface $manager,
        private Security $security,
        private iFoodService $iFoodService,
        private OrderActionService $orderActionService,
    ) {}

    private function normalizeString(mixed $value): string
    {
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

    private function getAuthenticatedPeople(): ?People
    {
        $user = $this->security->getToken()?->getUser();

        if (!is_object($user) || !method_exists($user, 'getPeople')) {
            return null;
        }

        $people = $user->getPeople();

        return $people instanceof People ? $people : null;
    }

    private function isAdminUser(): bool
    {
        $user = $this->security->getToken()?->getUser();
        $roles = is_object($user) && method_exists($user, 'getRoles') ? (array) $user->getRoles() : [];

        return in_array('ROLE_ADMIN', $roles, true);
    }

    private function parseJsonBody(Request $request): array
    {
        $content = trim((string) $request->getContent());
        if ($content === '') {
            return [];
        }

        $json = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
            throw new \InvalidArgumentException('Invalid JSON');
        }

        return $json;
    }

    private function decodeArrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return [];
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return [];
        }

        $decoded = json_decode($normalized, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function resolvePayloadSources(array $otherInformations): array
    {
        $sources = [];
        if ($otherInformations) {
            $sources[] = $otherInformations;
        }

        foreach (['iFood', 'ifood', 'IFOOD'] as $contextKey) {
            $contextPayload = $this->decodeArrayValue($otherInformations[$contextKey] ?? null);
            if ($contextPayload) {
                $sources[] = $contextPayload;
            }
        }

        return $sources;
    }

    private function resolveOrderPayload(array $payload): array
    {
        $orderPayload = $this->decodeArrayValue($payload['order'] ?? null);
        if ($orderPayload) {
            return $orderPayload;
        }

        if (
            isset($payload['displayId'])
            || isset($payload['delivery'])
            || isset($payload['items'])
            || isset($payload['payments'])
            || isset($payload['total'])
        ) {
            return $payload;
        }

        return [];
    }

    private function canAccessProvider(People $provider): bool
    {
        if ($this->isAdminUser()) {
            return true;
        }

        $userPeople = $this->getAuthenticatedPeople();
        if (!$userPeople) {
            return false;
        }

        if ($userPeople->getId() === $provider->getId()) {
            return true;
        }

        $sql = <<<SQL
            SELECT COUNT(1)
            FROM people_link
            WHERE company_id = :companyId
              AND people_id = :peopleId
              AND enable = 1
        SQL;

        $count = (int) $this->manager->getConnection()->fetchOne($sql, [
            'companyId' => $provider->getId(),
            'peopleId' => $userPeople->getId(),
        ]);

        return $count > 0;
    }

    private function resolveProvider(Request $request, array $payload = []): ?People
    {
        $providerId = $payload['provider_id']
            ?? $payload['company_id']
            ?? $request->query->get('provider_id')
            ?? $request->query->get('company_id');

        $userPeople = $this->getAuthenticatedPeople();

        if (!$providerId) {
            if ($userPeople && $this->canAccessProvider($userPeople)) {
                return $userPeople;
            }
            return null;
        }

        $providerId = (int) preg_replace('/\D+/', '', (string) $providerId);
        if ($providerId <= 0) {
            return null;
        }

        $provider = $this->manager->getRepository(People::class)->find($providerId);
        if (!$provider instanceof People) {
            return null;
        }

        return $this->canAccessProvider($provider) ? $provider : null;
    }

    private function canAccessOrder(Order $order): bool
    {
        $provider = $order->getProvider();
        if (!$provider instanceof People) {
            return false;
        }

        return $this->canAccessProvider($provider);
    }

    private function resolveOrder(string|int $orderId): ?Order
    {
        $id = (int) preg_replace('/\D+/', '', (string) $orderId);
        if ($id <= 0) {
            return null;
        }

        $order = $this->manager->getRepository(Order::class)->find($id);
        if (!$order instanceof Order) {
            return null;
        }

        if (!$this->canAccessOrder($order)) {
            return null;
        }

        return $order;
    }

    private function isIfoodOrder(Order $order): bool
    {
        return $this->normalizeString($order->getApp()) !== ''
            && strtolower($this->normalizeString($order->getApp())) === 'ifood';
    }

    private function orderNotFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'Pedido nao encontrado ou acesso negado'],
            Response::HTTP_FORBIDDEN
        );
    }

    private function providerNotFound(): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'Provider nao encontrado ou acesso negado'],
            Response::HTTP_FORBIDDEN
        );
    }

    private function hasErrnoError(mixed $value): bool
    {
        $normalized = $this->normalizeString($value);
        if ($normalized === '') {
            return false;
        }

        return $normalized !== '0';
    }

    private function resolveAgeInMinutes(mixed $dateTimeValue): ?int
    {
        $normalized = $this->normalizeString($dateTimeValue);
        if ($normalized === '') {
            return null;
        }

        try {
            $dateTime = new DateTimeImmutable($normalized);
        } catch (\Throwable) {
            return null;
        }

        $now = new DateTimeImmutable('now');
        $seconds = $now->getTimestamp() - $dateTime->getTimestamp();
        if ($seconds < 0) {
            return 0;
        }

        return (int) floor($seconds / 60);
    }

    private function resolveRemoteOrderStateLabel(?string $state): string
    {
        return match (strtolower($this->normalizeString($state))) {
            'new', 'placed' => 'Novo',
            'confirmed' => 'Confirmado',
            'preparing', 'started' => 'Preparando',
            'ready' => 'Pronto',
            'dispatching', 'dispatched' => 'Em entrega',
            'concluded', 'closed' => 'Concluido',
            'cancelled', 'canceled' => 'Cancelado',
            'cancellation_requested' => 'Cancelamento solicitado',
            default => 'Indefinido',
        };
    }

    private function decodeOrderOtherInformations(Order $order): array
    {
        $raw = $order->getOtherInformations(true);
        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function payloadHasOrderData(array $payload): bool
    {
        $orderData = $this->resolveOrderPayload($payload);
        if (!$orderData) {
            return false;
        }

        if (!empty($orderData['displayId'])) {
            return true;
        }

        if (!empty($orderData['delivery']) || !empty($orderData['items']) || !empty($orderData['payments'])) {
            return true;
        }

        return !empty($orderData['total']);
    }

    private function resolveLatestOrderPayload(Order $order): array
    {
        $otherInformations = $this->decodeOrderOtherInformations($order);
        if (!$otherInformations) {
            return [];
        }

        $firstEventPayload = [];

        foreach ($this->resolvePayloadSources($otherInformations) as $source) {
            $latestEventType = $this->normalizeString(
                $source['latest_event_type']
                    ?? $otherInformations['latest_event_type']
                    ?? null
            );
            if ($latestEventType !== '') {
                $latestPayload = $this->decodeArrayValue($source[$latestEventType] ?? null);
                if ($latestPayload && $this->payloadHasOrderData($latestPayload)) {
                    return $latestPayload;
                }
            }

            foreach ($source as $value) {
                $eventPayload = $this->decodeArrayValue($value);
                if (!$eventPayload || !isset($eventPayload['orderId'])) {
                    continue;
                }

                if ($firstEventPayload === []) {
                    $firstEventPayload = $eventPayload;
                }

                if ($this->payloadHasOrderData($eventPayload)) {
                    return $eventPayload;
                }
            }
        }

        return $firstEventPayload;
    }

    private function resolveDeliveryContext(Order $order, array $payload, array $storedState): array
    {
        $delivery = is_array($payload['order']['delivery'] ?? null) ? $payload['order']['delivery'] : [];
        $deliveredBy = strtoupper($this->normalizeString(
            $delivery['deliveredBy']
                ?? $storedState['delivered_by']
                ?? null
        ));
        $deliveryMode = strtolower($this->normalizeString(
            $delivery['mode']
                ?? $delivery['deliveryMode']
                ?? $storedState['delivery_mode']
                ?? null
        ));

        $isStoreDelivery = $deliveredBy === 'MERCHANT'
            || in_array($deliveryMode, ['merchant', 'store', 'self', 'self_delivery', 'own', 'own_fleet'], true);
        $isPlatformDelivery = $deliveredBy === 'IFOOD'
            || in_array($deliveryMode, ['ifood', 'platform', 'marketplace'], true);

        $deliveryLabel = 'Entrega indefinida';
        if ($isStoreDelivery) {
            $deliveryLabel = 'Entrega da loja';
        } elseif ($isPlatformDelivery) {
            $deliveryLabel = 'Entrega iFood';
        }

        return [
            'delivery_label' => $deliveryLabel,
            'is_store_delivery' => $isStoreDelivery,
            'is_platform_delivery' => $isPlatformDelivery,
            'delivered_by' => $deliveredBy !== '' ? $deliveredBy : null,
            'delivery_mode' => $deliveryMode !== '' ? $deliveryMode : null,
        ];
    }

    private function preferredText(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            $normalized = $this->normalizeString($value);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function extractAddressFromOrderEntity(Order $order): array
    {
        $address = method_exists($order, 'getAddressDestination') ? $order->getAddressDestination() : null;
        if (!is_object($address)) {
            return [];
        }

        $read = function (array $methods) use ($address): ?string {
            foreach ($methods as $method) {
                if (!method_exists($address, $method)) {
                    continue;
                }

                $value = $this->normalizeString($address->{$method}());
                if ($value !== '') {
                    return $value;
                }
            }

            return null;
        };

        return [
            'display' => $this->preferredText(
                $read(['getDisplay', 'getFormattedAddress']),
                $read(['getAddress']),
            ),
            'street_name' => $read(['getStreetName', 'getStreet', 'getAddress']),
            'street_number' => $read(['getStreetNumber', 'getNumber']),
            'district' => $read(['getDistrict', 'getNeighborhood']),
            'city' => $read(['getCity']),
            'state' => $read(['getState']),
            'postal_code' => $read(['getPostalCode', 'getZipCode', 'getZip']),
            'reference' => $read(['getReference']),
            'complement' => $read(['getComplement']),
            'poi_address' => $read(['getFormattedAddress', 'getAddress']),
        ];
    }

    private function buildAddressDetail(Order $order, array $payload, array $storedState): array
    {
        $orderPayload = is_array($payload['order'] ?? null) ? $payload['order'] : [];
        $delivery = is_array($orderPayload['delivery'] ?? null) ? $orderPayload['delivery'] : [];
        $deliveryAddress = is_array($delivery['deliveryAddress'] ?? null) ? $delivery['deliveryAddress'] : [];
        $entityAddress = $this->extractAddressFromOrderEntity($order);

        $streetName = $this->preferredText(
            $deliveryAddress['streetName'] ?? null,
            $storedState['address_street_name'] ?? null,
            $entityAddress['street_name'] ?? null
        );
        $streetNumber = $this->preferredText(
            $deliveryAddress['streetNumber'] ?? null,
            $storedState['address_street_number'] ?? null,
            $entityAddress['street_number'] ?? null
        );
        $district = $this->preferredText(
            $deliveryAddress['neighborhood'] ?? null,
            $storedState['address_district'] ?? null,
            $entityAddress['district'] ?? null
        );
        $city = $this->preferredText(
            $deliveryAddress['city'] ?? null,
            $storedState['address_city'] ?? null,
            $entityAddress['city'] ?? null
        );
        $state = $this->preferredText(
            $deliveryAddress['state'] ?? null,
            $storedState['address_state'] ?? null,
            $entityAddress['state'] ?? null
        );

        $display = $this->preferredText(
            $deliveryAddress['formattedAddress'] ?? null,
            $storedState['address_display'] ?? null,
            $entityAddress['display'] ?? null
        );

        if ($display === null) {
            $addressPieces = array_filter([$streetName, $streetNumber], fn($value) => $value !== null && $value !== '');
            $neighborhoodPieces = array_filter([$district, $city], fn($value) => $value !== null && $value !== '');
            $display = trim(implode(', ', array_filter([
                implode(', ', $addressPieces),
                implode(' - ', $neighborhoodPieces),
            ], fn($value) => $value !== '')));
            $display = $display !== '' ? $display : null;
        }

        return [
            'display' => $display,
            'street_name' => $streetName,
            'street_number' => $streetNumber,
            'district' => $district,
            'city' => $city,
            'state' => $state,
            'postal_code' => $this->preferredText(
                $deliveryAddress['postalCode'] ?? null,
                $storedState['address_postal_code'] ?? null,
                $entityAddress['postal_code'] ?? null
            ),
            'reference' => $this->preferredText(
                $deliveryAddress['reference'] ?? null,
                $storedState['address_reference'] ?? null,
                $entityAddress['reference'] ?? null
            ),
            'complement' => $this->preferredText(
                $deliveryAddress['complement'] ?? null,
                $storedState['address_complement'] ?? null,
                $entityAddress['complement'] ?? null
            ),
            'poi_address' => $this->preferredText(
                $deliveryAddress['formattedAddress'] ?? null,
                $storedState['address_poi_address'] ?? null,
                $entityAddress['poi_address'] ?? null
            ),
        ];
    }

    private function buildCustomerDetail(array $payload, array $storedState, Order $order): array
    {
        $orderPayload = is_array($payload['order'] ?? null) ? $payload['order'] : [];
        $customer = is_array($orderPayload['customer'] ?? null) ? $orderPayload['customer'] : [];
        $phone = is_array($customer['phone'] ?? null) ? $customer['phone'] : [];
        $client = $order->getClient();
        $clientName = is_object($client) && method_exists($client, 'getName')
            ? $this->normalizeString($client->getName())
            : null;

        $name = $this->preferredText(
            $customer['name'] ?? null,
            $storedState['customer_name'] ?? null,
            $clientName
        );

        $customerPhone = $this->preferredText(
            $phone['number'] ?? null,
            $storedState['customer_phone'] ?? null
        );

        return [
            'name' => $name,
            'phone' => $customerPhone,
        ];
    }

    private function buildProviderIntegrationDetail(People $provider, bool $refreshRemote = false): array
    {
        $syncResult = null;
        if ($refreshRemote) {
            $syncResult = $this->iFoodService->syncIntegrationState($provider);
        }

        $storesResponse = $this->iFoodService->listMerchants();
        $stores = is_array($storesResponse['data']['merchants'] ?? null)
            ? $storesResponse['data']['merchants']
            : [];

        $integrationState = $this->iFoodService->getStoredIntegrationState($provider, true);
        $merchantId = $this->normalizeString($integrationState['merchant_id'] ?? null);

        $selectedStore = null;
        if ($merchantId !== '') {
            foreach ($stores as $store) {
                if ($this->normalizeString($store['merchant_id'] ?? null) === $merchantId) {
                    $selectedStore = $store;
                    break;
                }
            }
        }

        return [
            'provider' => [
                'id' => $provider->getId(),
                'name' => method_exists($provider, 'getName') ? $provider->getName() : null,
            ],
            'integration' => [
                'key' => 'ifood',
                'label' => 'iFood',
                'minimum_required_items' => 1,
                'eligible_product_count' => $this->iFoodService->countEligibleProducts($provider),
                'connected' => (bool) ($integrationState['connected'] ?? false),
                'remote_connected' => (bool) ($integrationState['remote_connected'] ?? false),
                'ifood_code' => $integrationState['ifood_code'] ?? null,
                'merchant_id' => $integrationState['merchant_id'] ?? null,
                'merchant_name' => $integrationState['merchant_name'] ?? null,
                'merchant_status' => $integrationState['merchant_status'] ?? null,
                'merchant_status_label' => $integrationState['merchant_status_label'] ?? 'Indefinido',
                'online' => (bool) ($integrationState['online'] ?? false),
                'auth_available' => (bool) ($integrationState['auth_available'] ?? false),
                'connected_at' => $integrationState['connected_at'] ?? null,
                'last_sync_at' => $integrationState['last_sync_at'] ?? null,
                'last_error_code' => $integrationState['last_error_code'] ?? null,
                'last_error_message' => $integrationState['last_error_message'] ?? null,
            ],
            'stores' => [
                'errno' => $storesResponse['errno'] ?? 1,
                'errmsg' => $storesResponse['errmsg'] ?? 'Falha ao listar lojas',
                'items' => $stores,
                'total' => count($stores),
            ],
            'selected_store' => $selectedStore,
            'store_error' => (int) ($storesResponse['errno'] ?? 1) === 0 ? null : [
                'errno' => $storesResponse['errno'] ?? 1,
                'errmsg' => $storesResponse['errmsg'] ?? 'Falha ao listar lojas',
            ],
            'sync' => $syncResult,
        ];
    }

    private function buildOrderIntegrationDetail(Order $order): array
    {
        $storedState = $this->iFoodService->getStoredOrderIntegrationState($order);
        $capabilities = $this->orderActionService->getCapabilities($order);
        $payload = $this->resolveLatestOrderPayload($order);
        $orderPayload = $this->resolveOrderPayload($payload);
        $deliveryContext = $this->resolveDeliveryContext($order, $payload, $storedState);
        $remoteState = $this->normalizeString($storedState['remote_order_state'] ?? null);
        $orderComments = method_exists($order, 'getComments')
            ? $this->normalizeString($order->getComments())
            : '';

        $orderIndex = $this->normalizeString(
            $orderPayload['displayId']
                ?? $payload['displayId']
                ?? $storedState['ifood_code']
                ?? null
        );

        $remark = $this->normalizeString(
            $orderPayload['additionalInfo']['notes']
                ?? $orderPayload['additionalInfo']
                ?? $orderPayload['orderComment']
                ?? $storedState['remark']
                ?? $orderComments
                ?? null
        );

        return [
            'order' => [
                'id' => $order->getId(),
                'app' => $order->getApp(),
                'status' => [
                    'id' => $order->getStatus()?->getId(),
                    'status' => $order->getStatus()?->getStatus(),
                    'real_status' => $order->getStatus()?->getRealStatus(),
                ],
            ],
            'integration' => [
                'key' => 'ifood',
                'ifood_id' => $storedState['ifood_id'] ?? null,
                'ifood_code' => $storedState['ifood_code'] ?? null,
                'merchant_id' => $storedState['merchant_id'] ?? null,
                'remote_order_state' => $remoteState,
                'remote_order_state_label' => $this->resolveRemoteOrderStateLabel($remoteState),
                'last_event_type' => $storedState['last_event_type'] ?? null,
                'last_event_at' => $storedState['last_event_at'] ?? null,
                'last_action' => $storedState['last_action'] ?? null,
                'last_action_at' => $storedState['last_action_at'] ?? null,
                'last_action_errno' => $storedState['last_action_errno'] ?? null,
                'last_action_message' => $storedState['last_action_message'] ?? null,
                'cancel_reason' => $storedState['cancel_reason'] ?? null,
                'webhook_event_id' => $storedState['webhook_event_id'] ?? null,
                'webhook_event_type' => $storedState['webhook_event_type'] ?? null,
                'webhook_event_at' => $storedState['webhook_event_at'] ?? null,
                'webhook_received_at' => $storedState['webhook_received_at'] ?? null,
                'webhook_processed_at' => $storedState['webhook_processed_at'] ?? null,
                'last_integration_id' => $storedState['last_integration_id'] ?? null,
            ],
            'customer' => $this->buildCustomerDetail($payload, $storedState, $order),
            'address' => $this->buildAddressDetail($order, $payload, $storedState),
            'delivery'      => $this->buildDeliveryDetail($payload, $storedState, $remoteState, $deliveryContext, $capabilities),
            'financial'     => $this->buildFinancialDetail($payload),
            'observability' => [
                'has_action_error' => $this->hasErrnoError($storedState['last_action_errno'] ?? null),
                'is_healthy' => !$this->hasErrnoError($storedState['last_action_errno'] ?? null),
                'remote_state_age_minutes' => $this->resolveAgeInMinutes($storedState['last_event_at'] ?? null),
                'last_action_age_minutes' => $this->resolveAgeInMinutes($storedState['last_action_at'] ?? null),
            ],
            'identifiers' => [
                'order_index' => $orderIndex,
            ],
            'notes' => [
                'remark'          => $remark,
                'item_remarks'    => $this->extractItemRemarks($payload),
            ],
            'capabilities' => $capabilities,
        ];
    }

    #[Route('/marketplace/integrations/ifood/detail', name: 'marketplace_integrations_ifood_detail', methods: ['GET'])]
    public function getIntegrationDetail(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $refreshRemote = filter_var((string) $request->query->get('refresh_remote', ''), FILTER_VALIDATE_BOOLEAN);

        return new JsonResponse($this->buildProviderIntegrationDetail($provider, $refreshRemote));
    }

    #[Route('/marketplace/integrations/ifood/stores', name: 'marketplace_integrations_ifood_stores', methods: ['GET'])]
    public function getStores(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $storesResponse = $this->iFoodService->listMerchants();
        $stores = is_array($storesResponse['data']['merchants'] ?? null) ? $storesResponse['data']['merchants'] : [];

        return new JsonResponse([
            'provider' => [
                'id' => $provider->getId(),
                'name' => method_exists($provider, 'getName') ? $provider->getName() : null,
            ],
            'stores' => [
                'errno' => $storesResponse['errno'] ?? 1,
                'errmsg' => $storesResponse['errmsg'] ?? 'Falha ao listar lojas',
                'items' => $stores,
                'total' => count($stores),
            ],
            'integration' => $this->iFoodService->getStoredIntegrationState($provider, true),
        ]);
    }

    #[Route('/marketplace/integrations/ifood/store/connect', name: 'marketplace_integrations_ifood_store_connect', methods: ['POST'])]
    public function connectStore(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $merchantId = $this->normalizeString(
            $payload['merchant_id']
                ?? $payload['ifood_code']
                ?? $payload['store_id']
                ?? null
        );

        if ($merchantId === '') {
            return new JsonResponse([
                'error' => 'merchant_id obrigatorio',
            ], Response::HTTP_BAD_REQUEST);
        }

        $storesResponse = $this->iFoodService->listMerchants();
        $storePayload = null;
        foreach ((array) ($storesResponse['data']['merchants'] ?? []) as $store) {
            if ($this->normalizeString($store['merchant_id'] ?? null) === $merchantId) {
                $storePayload = $store;
                break;
            }
        }

        $result = $this->iFoodService->connectStore($provider, $merchantId, $storePayload);

        return new JsonResponse(array_merge(
            $this->buildProviderIntegrationDetail($provider, true),
            ['action' => 'connect', 'result' => $result]
        ));
    }

    #[Route('/marketplace/integrations/ifood/store/disconnect', name: 'marketplace_integrations_ifood_store_disconnect', methods: ['POST'])]
    public function disconnectStore(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $result = $this->iFoodService->disconnectStore($provider);

        return new JsonResponse(array_merge(
            $this->buildProviderIntegrationDetail($provider, false),
            ['action' => 'disconnect', 'result' => $result]
        ));
    }

    #[Route('/marketplace/integrations/ifood/sync', name: 'marketplace_integrations_ifood_sync', methods: ['POST'])]
    public function syncIntegrationState(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $sync = $this->iFoodService->syncIntegrationState($provider);

        return new JsonResponse(array_merge(
            $this->buildProviderIntegrationDetail($provider, false),
            ['action' => 'sync', 'result' => $sync]
        ));
    }

    #[Route('/marketplace/integrations/ifood/store/status', name: 'marketplace_integrations_ifood_store_status', methods: ['GET'])]
    public function getStoreStatus(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request, []);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $result = $this->iFoodService->getStoreStatus($provider);

        return new JsonResponse(array_merge(
            $this->buildProviderIntegrationDetail($provider, false),
            ['action' => 'store_status', 'result' => $result]
        ));
    }

    #[Route('/marketplace/integrations/ifood/store/open', name: 'marketplace_integrations_ifood_store_open', methods: ['POST'])]
    public function openStore(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $result = $this->iFoodService->openStore($provider);

        return new JsonResponse(array_merge(
            $this->buildProviderIntegrationDetail($provider, false),
            ['action' => 'store_open', 'result' => $result]
        ));
    }

    #[Route('/marketplace/integrations/ifood/store/close', name: 'marketplace_integrations_ifood_store_close', methods: ['POST'])]
    public function closeStore(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $result = $this->iFoodService->closeStore($provider);

        return new JsonResponse(array_merge(
            $this->buildProviderIntegrationDetail($provider, false),
            ['action' => 'store_close', 'result' => $result]
        ));
    }

    #[Route('/marketplace/integrations/ifood/menu/products', name: 'marketplace_integrations_ifood_menu_products', methods: ['GET'])]
    public function getMenuProducts(Request $request): JsonResponse
    {
        $provider = $this->resolveProvider($request, []);
        if (!$provider) {
            return $this->providerNotFound();
        }

        try {
            $products = $this->iFoodService->listSelectableMenuProducts($provider);
        } catch (\Throwable $e) {
            $products = ['products' => [], 'eligible_product_count' => 0, 'minimum_required_items' => 1];
        }

        return new JsonResponse(array_merge(
            $this->buildProviderIntegrationDetail($provider, false),
            ['products' => $products]
        ));
    }

    #[Route('/marketplace/integrations/ifood/menu/upload', name: 'marketplace_integrations_ifood_menu_upload', methods: ['POST'])]
    public function uploadMenu(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $rawIds     = $payload['product_ids'] ?? $payload['products'] ?? [];
        $productIds = is_array($rawIds) ? array_values(array_filter(array_map('intval', $rawIds))) : [];

        try {
            $result = $this->iFoodService->publishMenu($provider, $productIds);
        } catch (\Throwable $e) {
            $result = ['errno' => 1, 'errmsg' => 'Falha ao iniciar upload de cardapio iFood.'];
        }

        /* recarrega lista de produtos com status atualizado */
        try {
            $products = $this->iFoodService->listSelectableMenuProducts($provider);
        } catch (\Throwable $e) {
            $products = null;
        }

        return new JsonResponse(array_merge(
            $this->buildProviderIntegrationDetail($provider, false),
            ['action' => 'menu_upload', 'result' => $result, 'products' => $products]
        ));
    }

    #[Route('/marketplace/integrations/ifood/menu/sync', name: 'marketplace_integrations_ifood_menu_sync', methods: ['POST'])]
    public function syncMenuFromIfood(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        try {
            $result = $this->iFoodService->syncCatalogFromIfood($provider);
        } catch (\Throwable $e) {
            $result = ['errno' => 1, 'errmsg' => 'Falha ao sincronizar catalogo do iFood.'];
        }

        try {
            $products = $this->iFoodService->listSelectableMenuProducts($provider);
        } catch (\Throwable $e) {
            $products = null;
        }

        return new JsonResponse(array_merge(
            $this->buildProviderIntegrationDetail($provider, false),
            ['action' => 'menu_sync', 'result' => $result, 'products' => $products]
        ));
    }

    #[Route('/marketplace/integrations/ifood/menu/item/price', name: 'marketplace_integrations_ifood_menu_item_price', methods: ['PATCH'])]
    public function updateMenuItemPrice(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $itemId = $this->normalizeString($payload['item_id'] ?? null);
        $price  = isset($payload['price']) ? (float) $payload['price'] : 0.0;

        if ($itemId === '') {
            return new JsonResponse(['error' => 'item_id obrigatorio'], Response::HTTP_BAD_REQUEST);
        }
        if ($price <= 0) {
            return new JsonResponse(['error' => 'price deve ser maior que zero'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->iFoodService->updateItemPrice($provider, $itemId, $price);
        } catch (\Throwable $e) {
            $result = ['errno' => 1, 'errmsg' => 'Falha ao atualizar preco no iFood.'];
        }

        return new JsonResponse(['action' => 'item_price_update', 'result' => $result]);
    }

    #[Route('/marketplace/integrations/ifood/menu/item/status', name: 'marketplace_integrations_ifood_menu_item_status', methods: ['PATCH'])]
    public function updateMenuItemStatus(Request $request): JsonResponse
    {
        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $provider = $this->resolveProvider($request, $payload);
        if (!$provider) {
            return $this->providerNotFound();
        }

        $itemId = $this->normalizeString($payload['item_id'] ?? null);
        $status = $this->normalizeString($payload['status'] ?? null);

        if ($itemId === '') {
            return new JsonResponse(['error' => 'item_id obrigatorio'], Response::HTTP_BAD_REQUEST);
        }
        if (!in_array(strtoupper($status), ['AVAILABLE', 'UNAVAILABLE'], true)) {
            return new JsonResponse(['error' => 'status deve ser AVAILABLE ou UNAVAILABLE'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->iFoodService->updateItemStatus($provider, $itemId, $status);
        } catch (\Throwable $e) {
            $result = ['errno' => 1, 'errmsg' => 'Falha ao atualizar status no iFood.'];
        }

        return new JsonResponse(['action' => 'item_status_update', 'result' => $result]);
    }

    #[Route('/marketplace/integrations/ifood/orders/{orderId}/ready', name: 'marketplace_integrations_ifood_order_ready', methods: ['POST'])]
    public function readyOrderAction(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderNotFound();
        }

        if (!$this->isIfoodOrder($order)) {
            return new JsonResponse(['error' => 'Order is not linked to iFood'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->iFoodService->performReadyAction($order);
        } catch (\Throwable $e) {
            $result = [
                'errno' => 1,
                'errmsg' => 'Falha ao executar acao ready no iFood: ' . $this->normalizeString($e->getMessage()),
            ];
        }

        return new JsonResponse([
            'action' => 'ready',
            'result' => $result,
            'state' => $this->buildOrderIntegrationDetail($order),
        ]);
    }

    #[Route('/marketplace/integrations/ifood/orders/{orderId}/cancel', name: 'marketplace_integrations_ifood_order_cancel', methods: ['POST'])]
    public function cancelOrderAction(string $orderId, Request $request): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderNotFound();
        }

        if (!$this->isIfoodOrder($order)) {
            return new JsonResponse(['error' => 'Order is not linked to iFood'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $payload = $this->parseJsonBody($request);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        $reason           = $this->normalizeString($payload['reason'] ?? null);
        $cancellationCode = $this->normalizeString($payload['reason_id'] ?? $payload['cancellationCode'] ?? null);
        try {
            $result = $this->iFoodService->performCancelAction(
                $order,
                $reason !== '' ? $reason : null,
                $cancellationCode !== '' ? $cancellationCode : null
            );
        } catch (\Throwable $e) {
            $result = [
                'errno' => 1,
                'errmsg' => 'Falha ao executar acao cancel no iFood: ' . $this->normalizeString($e->getMessage()),
            ];
        }

        return new JsonResponse([
            'action' => 'cancel',
            'result' => $result,
            'state' => $this->buildOrderIntegrationDetail($order),
        ]);
    }

    #[Route('/marketplace/integrations/ifood/orders/{orderId}/delivered', name: 'marketplace_integrations_ifood_order_delivered', methods: ['POST'])]
    public function deliveredOrderAction(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderNotFound();
        }

        if (!$this->isIfoodOrder($order)) {
            return new JsonResponse(['error' => 'Order is not linked to iFood'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->iFoodService->performDeliveredAction($order);
        } catch (\Throwable $e) {
            $result = [
                'errno' => 1,
                'errmsg' => 'Falha ao executar acao delivered no iFood: ' . $this->normalizeString($e->getMessage()),
            ];
        }

        return new JsonResponse([
            'action' => 'delivered',
            'result' => $result,
            'state' => $this->buildOrderIntegrationDetail($order),
        ]);
    }

    #[Route('/marketplace/integrations/ifood/orders/{orderId}/state', name: 'marketplace_integrations_ifood_order_state', methods: ['GET'])]
    public function getOrderState(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderNotFound();
        }

        if (!$this->isIfoodOrder($order)) {
            return new JsonResponse([
                'error' => 'Order is not linked to iFood',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse($this->buildOrderIntegrationDetail($order));
    }

    #[Route('/marketplace/integrations/ifood/orders/{orderId}/confirm', name: 'marketplace_integrations_ifood_order_confirm', methods: ['POST'])]
    public function confirmOrderAction(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderNotFound();
        }

        if (!$this->isIfoodOrder($order)) {
            return new JsonResponse(['error' => 'Order is not linked to iFood'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->iFoodService->performConfirmAction($order);
        } catch (\Throwable $e) {
            $result = [
                'errno' => 1,
                'errmsg' => 'Falha ao confirmar pedido no iFood: ' . $this->normalizeString($e->getMessage()),
            ];
        }

        return new JsonResponse([
            'action' => 'confirm',
            'result' => $result,
            'state'  => $this->buildOrderIntegrationDetail($order),
        ]);
    }

    #[Route('/marketplace/integrations/ifood/orders/{orderId}/start-preparation', name: 'marketplace_integrations_ifood_order_start_preparation', methods: ['POST'])]
    public function startPreparationOrderAction(string $orderId): JsonResponse
    {
        $order = $this->resolveOrder($orderId);
        if (!$order) {
            return $this->orderNotFound();
        }

        if (!$this->isIfoodOrder($order)) {
            return new JsonResponse(['error' => 'Order is not linked to iFood'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->iFoodService->performStartPreparationAction($order);
        } catch (\Throwable $e) {
            $result = [
                'errno' => 1,
                'errmsg' => 'Falha ao iniciar preparo do pedido no iFood: ' . $this->normalizeString($e->getMessage()),
            ];
        }

        return new JsonResponse([
            'action' => 'start_preparation',
            'result' => $result,
            'state'  => $this->buildOrderIntegrationDetail($order),
        ]);
    }

    private function buildFinancialDetail(array $payload): array
    {
        $order    = $this->resolveOrderPayload($payload);
        $total    = is_array($order['total'] ?? null) ? $order['total'] : [];
        $methods  = is_array($order['payments']['methods'] ?? null) ? $order['payments']['methods'] : [];
        $benefits = is_array($order['benefits'] ?? null) ? $order['benefits'] : [];

        $itemsTotal    = (float) ($total['itemsPrice'] ?? $total['subTotal'] ?? 0.0);
        $deliveryFee   = (float) ($total['deliveryFee'] ?? 0.0);
        $additionalFees = (float) ($total['additionalFees'] ?? 0.0);
        $orderAmount   = (float) ($total['orderAmount'] ?? 0.0);

        /* descontos separados: iFood vs loja */
        $ifoodSubsidy    = 0.0;
        $merchantSubsidy = 0.0;
        $discountTotal   = 0.0;
        foreach ($benefits as $benefit) {
            $discountTotal += (float) ($benefit['value'] ?? 0.0);
            foreach ((array) ($benefit['sponsorshipValues'] ?? []) as $sponsor) {
                $name  = strtoupper($this->normalizeString($sponsor['name'] ?? null));
                $value = (float) ($sponsor['value'] ?? 0.0);
                if ($name === 'IFOOD') {
                    $ifoodSubsidy += $value;
                } else {
                    $merchantSubsidy += $value;
                }
            }
        }

        /* bandeira do cartão e troco */
        $paymentBrand  = null;
        $changeFor     = null;
        $paymentLabels = [];
        foreach ($methods as $m) {
            $type  = strtoupper($this->normalizeString($m['type'] ?? null));
            $brand = $this->normalizeString($m['card']['brand'] ?? null);
            if ($brand !== '') {
                $paymentBrand = $brand;
            }
            $change = $m['cash']['changeFor'] ?? null;
            if ($change !== null) {
                $changeFor = (float) $change;
            }
            if ($type !== '') {
                $paymentLabels[] = $type . ($brand !== '' ? " ($brand)" : '');
            }
        }

        return [
            'items_total'      => $itemsTotal,
            'delivery_fee'     => $deliveryFee,
            'additional_fees'  => $additionalFees,
            'order_amount'     => $orderAmount,
            'discount_total'   => $discountTotal,
            'ifood_subsidy'    => $ifoodSubsidy,
            'merchant_subsidy' => $merchantSubsidy,
            'payment_brand'    => $paymentBrand,
            'change_for'       => $changeFor,
            'payment_labels'   => $paymentLabels,
        ];
    }

    private function extractItemRemarks(array $payload): array
    {
        $orderPayload = $this->resolveOrderPayload($payload);
        $items = is_array($orderPayload['items'] ?? null) ? $orderPayload['items'] : [];
        $remarks = [];
        foreach ($items as $item) {
            $obs = $this->normalizeString($item['observations'] ?? $item['notes'] ?? null);
            if ($obs !== '') {
                $remarks[] = [
                    'name'        => $this->normalizeString($item['name'] ?? null),
                    'observation' => $obs,
                ];
            }
        }
        return $remarks;
    }

    private function buildDeliveryDetail(
        array $payload,
        array $storedState,
        string $remoteState,
        array $deliveryContext,
        array $capabilities
    ): array {
        $orderPayload = $this->resolveOrderPayload($payload);
        $delivery = is_array($orderPayload['delivery'] ?? null) ? $orderPayload['delivery'] : [];
        $deliveryAddress = is_array($delivery['deliveryAddress'] ?? null) ? $delivery['deliveryAddress'] : [];
        $pickup = is_array($orderPayload['pickup'] ?? null) ? $orderPayload['pickup'] : [];
        $customer = is_array($orderPayload['customer'] ?? null) ? $orderPayload['customer'] : [];
        $customerPhone = is_array($customer['phone'] ?? null) ? $customer['phone'] : [];
        $liveTracking = is_array($delivery['liveTracking'] ?? null)
            ? $delivery['liveTracking'] : [];
        $riderData    = is_array($liveTracking['rider'] ?? null) ? $liveTracking['rider'] : [];

        $pickupCode = $this->normalizeString(
            $pickup['code']
                ?? $delivery['pickupCode']
                ?? $storedState['pickup_code']
                ?? null
        );
        $handoverCode = $this->normalizeString(
            $storedState['handover_code']
                ?? $pickupCode
                ?? null
        );
        $locator = $this->normalizeString(
            $delivery['locator']
                ?? $delivery['localizer']
                ?? $deliveryAddress['locator']
                ?? $deliveryAddress['localizer']
                ?? $customerPhone['localizer']
                ?? $storedState['locator']
                ?? null
        );
        $handoverPageUrl = $this->normalizeString(
            $delivery['handoverPageUrl']
                ?? $delivery['handover_page_url']
                ?? $deliveryAddress['handoverPageUrl']
                ?? $deliveryAddress['handover_page_url']
                ?? $storedState['handover_page_url']
                ?? null
        );
        $handoverConfirmationUrl = $this->normalizeString(
            $delivery['handoverConfirmationUrl']
                ?? $delivery['handover_confirmation_url']
                ?? $deliveryAddress['handoverConfirmationUrl']
                ?? $deliveryAddress['handover_confirmation_url']
                ?? $delivery['confirmationUrl']
                ?? $delivery['confirmation_url']
                ?? $deliveryAddress['confirmationUrl']
                ?? $deliveryAddress['confirmation_url']
                ?? $storedState['handover_confirmation_url']
                ?? null
        );

        if ($handoverConfirmationUrl === '' && $handoverPageUrl !== '') {
            $handoverConfirmationUrl = $handoverPageUrl;
        }
        if ($handoverPageUrl === '' && $handoverConfirmationUrl !== '') {
            $handoverPageUrl = $handoverConfirmationUrl;
        }
        if (
            $handoverConfirmationUrl === ''
            && ($deliveryContext['is_store_delivery'] ?? false)
        ) {
            $handoverConfirmationUrl = self::IFOOD_SELF_DELIVERY_CONFIRMATION_URL;
            $handoverPageUrl = $handoverPageUrl !== '' ? $handoverPageUrl : $handoverConfirmationUrl;
        }

        $virtualPhone  = $this->normalizeString(
            $customerPhone['localizer']
                ?? $deliveryAddress['localizer']
                ?? $storedState['virtual_phone']
                ?? null
        );
        $riderName     = $this->normalizeString($riderData['name'] ?? null);
        $riderPhone    = $this->normalizeString($riderData['phone']['number'] ?? null);
        $riderToStore  = $this->normalizeString($liveTracking['riderToStoreEta'] ?? null);
        $expectedEta   = $this->normalizeString(
            $delivery['deliveryDateTime']
                ?? $delivery['estimatedDeliveryDate']
                ?? $storedState['expected_arrived_eta']
                ?? null
        );

        return [
            'delivery_label'                  => $deliveryContext['delivery_label'],
            'remote_delivery_status'          => $remoteState,
            'expected_arrived_eta'            => $expectedEta !== '' ? $expectedEta : null,
            'pickup_code'                     => $pickupCode !== '' ? $pickupCode : null,
            'delivered_by'                    => $deliveryContext['delivered_by'] ?? null,
            'delivery_mode'                   => $deliveryContext['delivery_mode'] ?? null,
            'handover_code'                   => $handoverCode !== '' ? $handoverCode : null,
            'locator'                         => $locator !== '' ? $locator : null,
            'handover_page_url'               => $handoverPageUrl !== '' ? $handoverPageUrl : null,
            'handover_confirmation_url'       => $handoverConfirmationUrl !== '' ? $handoverConfirmationUrl : null,
            'virtual_phone_number'            => $virtualPhone !== '' ? $virtualPhone : null,
            'rider_name'                      => $riderName !== '' ? $riderName : null,
            'rider_phone'                     => $riderPhone !== '' ? $riderPhone : null,
            'rider_to_store_eta'              => $riderToStore !== '' ? $riderToStore : null,
            'is_store_delivery'               => $deliveryContext['is_store_delivery'],
            'is_platform_delivery'            => $deliveryContext['is_platform_delivery'],
            'allows_manual_delivery_completion' => (bool) ($capabilities['can_delivered'] ?? false),
        ];
    }
}
